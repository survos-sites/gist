<?php
// src/Command/BrowseFreeDictCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Repository\FreeDictCatalogRepository;
use App\Service\FreeDictService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:browse', description: 'Browse a dictionary (downloads/extracts if needed) and show the first record')]
final class BrowseFreeDictCommand
{
    public function __construct(
        private readonly FreeDictCatalogRepository $repo,
        private readonly FreeDictService $freeDict,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dictionary pair slug, e.g. "eng-deu"')]
        string $dictPair,
        #[Option('Force re-download/extract even if cached locally', shortcut: 'f')]
        bool $force = false,
        #[Option('Output format: text|json (default text)', shortcut: 'F')]
        string $format = 'text',
    ): int {
        $io->title("Browsing FreeDict: $dictPair");

        $row = $this->repo->findOneBy(['name' => $dictPair]);
        if (!$row) {
            $io->error("No catalog row for '$dictPair'. Run: bin/console app:load");
            return 1;
        }

        if (($row->bestPlatform ?? '') !== 'stardict') {
            $io->warning("Skipping '$dictPair' — bestPlatform is '{$row->bestPlatform}', not 'stardict'.");
            return 0;
        }

        $url = $row->bestUrl;
        if (!$url) {
            $io->warning("Skipping '$dictPair' — no StarDict URL recorded.");
            return 0;
        }

        $pairDir = $this->freeDict->getCacheDir() . '/' . $dictPair;
        @\mkdir($pairDir, 0777, true);

        if ($force && \is_file("$pairDir/stardict.tar.xz")) @\unlink("$pairDir/stardict.tar.xz");
        if ($force && \is_dir("$pairDir/stardict")) $this->rrmdir("$pairDir/stardict");

        $io->writeln('Ensuring StarDict files are present…');
        $dictDir = $this->freeDict->ensureStarDictReady($dictPair, $url);

        $io->writeln('Reading first record…');
        $detailed = $this->freeDict->starDictFirstRecordDetailed($dictDir);

        if (!($detailed['found'] ?? false)) {
            $io->warning($detailed['message'] ?? 'No entry found.');
            return 0;
        }

        if (\strtolower($format) === 'json') {
            $io->writeln(\json_encode([
                'pair' => $dictPair,
                'catalog' => [
                    'name' => $row->name,
                    'src' => $row->src,
                    'dst' => $row->dst,
                    'edition' => $row->edition,
                    'headwords' => $row->headwords,
                    'maintainerName' => $row->maintainerName,
                    'status' => $row->status,
                    'release' => [
                        'platform' => $row->bestPlatform,
                        'url' => $row->bestUrl,
                        'date' => $row->releaseDate,
                        'version' => $row->releaseVersion,
                        'size' => $row->releaseSize,
                    ],
                ],
                'ifo' => $detailed['ifo'] ?? [],
                'paths' => $detailed['paths'] ?? [],
                'dict_size' => $detailed['dict_size'] ?? null,
                'reader' => $detailed['reader'] ?? null,
                'headword' => $detailed['headword'] ?? null,
                'offsets' => $detailed['offsets'] ?? null,
                // both raw & cleaned text to help decide DB import strategies
                'value_raw' => $detailed['value_raw'] ?? null,
                'value_text' => $detailed['value_text'] ?? null,
                'value_preview' => $detailed['value_preview'] ?? null,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_IGNORE));
            return 0;
        }

        // text mode (previous behavior)
        $io->section('Dictionary');
        $book = $detailed['ifo']['bookname'] ?? $dictPair;
        $io->writeln($book . '  [' . ($detailed['reader'] ?? 'unknown') . ']');

        $io->section('Headword');
        $io->writeln((string)($detailed['headword'] ?? '(unknown)'));

        $io->section('Value (snippet)');
        $io->writeln((string)($detailed['value_preview'] ?? '(empty)'));

        return 0;
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) return;
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
        }
        @\rmdir($dir);
    }
}
