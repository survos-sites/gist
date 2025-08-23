<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'freedict:explore',
    description: 'Explore FreeDict dictionary formats by downloading and examining files (from Claude)',
)]
class FreedictExploreCommand extends Command
{
    private HttpClientInterface $httpClient;
    private string $tempDir;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
        $this->tempDir = sys_get_temp_dir() . '/freedict_explorer';
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'language-pair',
                InputArgument::OPTIONAL,
                'Language pair to explore (e.g., "eng-spa", "deu-eng"). If not provided, will list available pairs.'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Preferred format to download (dict, freedict-p5, stardict, etc.)',
                'freedict-p5'
            )
            ->addOption(
                'list-formats',
                'l',
                InputOption::VALUE_NONE,
                'List available formats for the language pair'
            )
            ->addOption(
                'download-dir',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Directory to store downloaded files',
                null
            )
            ->addOption(
                'keep-files',
                'k',
                InputOption::VALUE_NONE,
                'Keep downloaded files after processing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $languagePair = $input->getArgument('language-pair');
        $preferredFormat = $input->getOption('format');
        $listFormats = $input->getOption('list-formats');
        $downloadDir = $input->getOption('download-dir') ?? $this->tempDir;
        $keepFiles = $input->getOption('keep-files');

        try {
            // Ensure download directory exists
            if (!is_dir($downloadDir)) {
                mkdir($downloadDir, 0755, true);
            }

            $io->title('FreeDict Dictionary Explorer');

            // Fetch the database
            $io->section('Fetching FreeDict database...');
            $database = $this->fetchFreeDictDatabase($io);

            if (!$languagePair) {
                $this->listAvailableLanguagePairs($database, $io);
                return Command::SUCCESS;
            }

            // Find the language pair in the database
            $dictInfo = $this->findLanguagePair($database, $languagePair);
            if (!$dictInfo) {
                $io->error("Language pair '$languagePair' not found in database.");
                $io->note('Use the command without arguments to see available language pairs.');
                return Command::FAILURE;
            }

            $io->section("Found dictionary: {$dictInfo['name']}");
            $io->text("Description: {$dictInfo['description']}");
            dump($dictInfo);
            $io->text("Language pair: {$dictInfo['lg1']} → {$dictInfo['lg2']}");

            // List available formats if requested
            if ($listFormats) {
                $this->listAvailableFormats($dictInfo, $io);
                return Command::SUCCESS;
            }

            // Download and explore the dictionary
            $downloadUrl = $this->findBestFormat($dictInfo, $preferredFormat, $io);
            if (!$downloadUrl) {
                $io->error("No suitable format found. Available formats:");
                $this->listAvailableFormats($dictInfo, $io);
                return Command::FAILURE;
            }

            $filePath = $this->downloadDictionary($downloadUrl, $downloadDir, $io);
            $extractedPath = $this->extractArchive($filePath, $downloadDir, $io);
            $this->exploreFirstRecord($extractedPath, $preferredFormat, $io);

            // Cleanup unless requested to keep files
            if (!$keepFiles) {
                $io->section('Cleaning up temporary files...');
                $this->cleanup($filePath, $extractedPath, $io);
            } else {
                $io->success("Files kept in: $downloadDir");
            }

        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fetchFreeDictDatabase(SymfonyStyle $io): array
    {
        $io->text('Downloading freedict-database.json...');

        $response = $this->httpClient->request('GET', 'https://freedict.org/freedict-database.json');
        $data = $response->toArray();

        $io->success(sprintf('Found %d dictionaries in database', count($data)));

        return $data;
    }

    private function listAvailableLanguagePairs(array $database, SymfonyStyle $io): void
    {
        $io->section('Available Language Pairs:');

        $pairs = [];
        foreach ($database as $dict) {
            $pair = "{$dict['lg1']}-{$dict['lg2']}";
            $pairs[] = [
                $pair,
                $dict['name'],
                $dict['description'] ?? 'No description',
                count($dict['releases']) . ' releases'
            ];
        }

        $io->table(['Pair', 'Name', 'Description', 'Releases'], $pairs);
        $io->note('Use a language pair as an argument to explore that dictionary.');
    }

    private function findLanguagePair(array $database, string $languagePair): ?array
    {
        foreach ($database as $dict) {
            $pair = "{$dict['lg1']}-{$dict['lg2']}";
            if ($pair === $languagePair || $dict['name'] === $languagePair) {
                return $dict;
            }
        }
        return null;
    }

    private function listAvailableFormats(array $dictInfo, SymfonyStyle $io): void
    {
        $io->section('Available Formats:');

        if (empty($dictInfo['releases'])) {
            $io->warning('No releases found for this dictionary.');
            return;
        }

        $formats = [];
        foreach ($dictInfo['releases'] as $release) {
            foreach ($release['formats'] as $format => $info) {
                $formats[] = [
                    $format,
                    $info['url'],
                    $this->formatFileSize($info['size'] ?? 0),
                    $release['version']
                ];
            }
        }

        $io->table(['Format', 'URL', 'Size', 'Version'], $formats);
    }

    private function findBestFormat(array $dictInfo, string $preferredFormat, SymfonyStyle $io): ?string
    {
        if (empty($dictInfo['releases'])) {
            return null;
        }

        // Get the latest release
        $latestRelease = $dictInfo['releases'][0]; // Assuming first is latest

        $io->text("Looking for format: $preferredFormat");

        // Try preferred format first
        if (isset($latestRelease['formats'][$preferredFormat])) {
            $url = $latestRelease['formats'][$preferredFormat]['url'];
            $io->success("Found preferred format: $preferredFormat");
            return $url;
        }

        // Fallback order: prioritize XML/text formats that are easier to parse in PHP
        $formatPriority = ['freedict-p5', 'tei', 'dict', 'stardict', 'slob'];

        foreach ($formatPriority as $format) {
            if (isset($latestRelease['formats'][$format])) {
                $url = $latestRelease['formats'][$format]['url'];
                $io->text("Using fallback format: $format");
                return $url;
            }
        }

        // If nothing else, use the first available format
        $firstFormat = array_key_first($latestRelease['formats']);
        if ($firstFormat) {
            $url = $latestRelease['formats'][$firstFormat]['url'];
            $io->warning("Using first available format: $firstFormat");
            return $url;
        }

        return null;
    }

    private function downloadDictionary(string $url, string $downloadDir, SymfonyStyle $io): string
    {
        $io->section('Downloading dictionary...');
        $io->text("URL: $url");

        $fileName = basename(parse_url($url, PHP_URL_PATH));
        $filePath = $downloadDir . '/' . $fileName;

        $io->progressStart();

        $response = $this->httpClient->request('GET', $url);
        $fileHandle = fopen($filePath, 'w');

        foreach ($this->httpClient->stream($response) as $chunk) {
            fwrite($fileHandle, $chunk->getContent());
            $io->progressAdvance();
        }

        fclose($fileHandle);
        $io->progressFinish();

        $io->success("Downloaded to: $filePath");
        $io->text("File size: " . $this->formatFileSize(filesize($filePath)));

        return $filePath;
    }

    private function extractArchive(string $filePath, string $downloadDir, SymfonyStyle $io): string
    {
        $io->section('Extracting archive...');

        $pathInfo = pathinfo($filePath);
        $extension = strtolower($pathInfo['extension']);
        $extractDir = $downloadDir . '/extracted_' . $pathInfo['filename'];

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        switch ($extension) {
            case 'gz':
            case 'tgz':
                $this->extractTarGz($filePath, $extractDir, $io);
                break;
            case 'zip':
                $this->extractZip($filePath, $extractDir, $io);
                break;
            case 'bz2':
                $this->extractBzip2($filePath, $extractDir, $io);
                break;
            default:
                // Maybe it's not compressed
                $io->text("File doesn't appear to be compressed, copying...");
                copy($filePath, $extractDir . '/' . $pathInfo['basename']);
        }

        $io->success("Extracted to: $extractDir");
        return $extractDir;
    }

    private function extractTarGz(string $filePath, string $extractDir, SymfonyStyle $io): void
    {
        $io->text('Extracting .tar.gz file...');

        $phar = new \PharData($filePath);
        $phar->extractTo($extractDir);

        $io->text('Extraction complete');
    }

    private function extractZip(string $filePath, string $extractDir, SymfonyStyle $io): void
    {
        $io->text('Extracting .zip file...');

        $zip = new \ZipArchive();
        if ($zip->open($filePath) === TRUE) {
            $zip->extractTo($extractDir);
            $zip->close();
            $io->text('Extraction complete');
        } else {
            throw new \RuntimeException('Failed to open ZIP file');
        }
    }

    private function extractBzip2(string $filePath, string $extractDir, SymfonyStyle $io): void
    {
        $io->text('Extracting .bz2 file...');

        $bz = bzopen($filePath, 'r');
        $outputFile = $extractDir . '/' . pathinfo($filePath, PATHINFO_FILENAME);
        $output = fopen($outputFile, 'w');

        while (!feof($bz)) {
            fwrite($output, bzread($bz, 8192));
        }

        bzclose($bz);
        fclose($output);

        $io->text('Extraction complete');
    }

    private function exploreFirstRecord(string $extractDir, string $format, SymfonyStyle $io): void
    {
        $io->section('Exploring dictionary format...');

        // Find all files in the extracted directory
        $files = $this->findDictionaryFiles($extractDir);

        if (empty($files)) {
            $io->warning('No dictionary files found in extracted archive');
            return;
        }

        foreach ($files as $file) {
            $io->text("Found file: " . basename($file));
            $this->examineFile($file, $format, $io);
        }
    }

    private function findDictionaryFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                $filename = strtolower($file->getFilename());

                // Look for common dictionary file patterns
                if (in_array($extension, ['xml', 'tei', 'dict', 'idx', 'dz', 'txt', 'json']) ||
                    str_contains($filename, 'dict') ||
                    str_contains($filename, '.tei')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function examineFile(string $filePath, string $format, SymfonyStyle $io): void
    {
        $io->text("\n--- Examining: " . basename($filePath) . " ---");
        $io->text("Format: $format");
        $io->text("Size: " . $this->formatFileSize(filesize($filePath)));

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'xml':
            case 'tei':
                $this->examineXmlFile($filePath, $io);
                break;
            case 'dict':
                $this->examineDictFile($filePath, $io);
                break;
            case 'json':
                $this->examineJsonFile($filePath, $io);
                break;
            default:
                $this->examineTextFile($filePath, $io);
        }
    }

    private function examineXmlFile(string $filePath, SymfonyStyle $io): void
    {
        $io->text("Reading XML file...");

        try {
            $reader = new \XMLReader();
            $reader->open($filePath);

            $entryCount = 0;
            $firstEntry = null;

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    // Look for common entry elements
                    if (in_array($reader->localName, ['entry', 'sense', 'form', 'orth', 'def'])) {
                        if ($reader->localName === 'entry' && $entryCount === 0) {
                            $firstEntry = $reader->readOuterXML();
                            $entryCount++;
                            break;
                        }
                    }
                }
            }

            $reader->close();

            if ($firstEntry) {
                $io->section('First entry found:');
                $io->text($firstEntry);
            } else {
                $io->warning('No entry elements found in XML');
                // Show first 1000 characters instead
                $content = file_get_contents($filePath, false, null, 0, 1000);
                $io->text('First 1000 characters:');
                $io->text($content);
            }

        } catch (\Exception $e) {
            $io->error('Error reading XML: ' . $e->getMessage());
            $this->examineTextFile($filePath, $io);
        }
    }

    private function examineDictFile(string $filePath, SymfonyStyle $io): void
    {
        $io->text("Reading DICT format file...");

        $handle = fopen($filePath, 'r');
        if ($handle) {
            $lineCount = 0;
            while (($line = fgets($handle)) !== false && $lineCount < 10) {
                $io->text("Line $lineCount: " . trim($line));
                $lineCount++;
            }
            fclose($handle);
        }
    }

    private function examineJsonFile(string $filePath, SymfonyStyle $io): void
    {
        $io->text("Reading JSON file...");

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if ($data) {
            $io->text('JSON structure:');
            if (is_array($data)) {
                $io->text('Array with ' . count($data) . ' elements');
                if (!empty($data)) {
                    $io->text('First element:');
                    $io->text(json_encode($data[0] ?? array_values($data)[0], JSON_PRETTY_PRINT));
                }
            } else {
                $io->text('Object with keys: ' . implode(', ', array_keys($data)));
            }
        } else {
            $io->error('Invalid JSON');
            $this->examineTextFile($filePath, $io);
        }
    }

    private function examineTextFile(string $filePath, SymfonyStyle $io): void
    {
        $io->text("Reading as text file...");

        $handle = fopen($filePath, 'r');
        if ($handle) {
            $lineCount = 0;
            while (($line = fgets($handle)) !== false && $lineCount < 20) {
                $io->text("Line $lineCount: " . trim($line));
                $lineCount++;
                if ($lineCount >= 20) {
                    $io->text("... (truncated)");
                    break;
                }
            }
            fclose($handle);
        }
    }

    private function cleanup(string $filePath, string $extractedPath, SymfonyStyle $io): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        if (is_dir($extractedPath)) {
            $this->deleteDirectory($extractedPath);
        }

        $io->text('Cleanup complete');
    }

    private function deleteDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) {
                rmdir($fileinfo->getPathname());
            } else {
                unlink($fileinfo->getPathname());
            }
        }

        rmdir($dir);
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
