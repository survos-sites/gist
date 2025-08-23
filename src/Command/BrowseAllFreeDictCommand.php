<?php
// src/Command/BrowseAllFreeDictCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Repository\FreeDictCatalogRepository;
use App\Service\FreeDictService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:browse:all', description: 'Loop through all StarDict dictionaries and print the first entry')]
final class BrowseAllFreeDictCommand
{
    public function __construct(
        private readonly FreeDictCatalogRepository $repo,
        private readonly FreeDictService $freeDict,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(shortcut: 'l', description: 'Limit number of dictionaries to process (0 = no limit)')]
        int $limit = 10,
        #[Option(shortcut: 'f', description: 'Force re-download/extract even if cached locally')]
        bool $force = false
    ): int {
        $rows = $this->repo->findBy([], ['name' => 'ASC']);
        $processed = 0;

        foreach ($rows as $row) {
            if (($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
                continue;
            }
            $pair = $row->name;

            $io->title("Browsing $pair");
            $pairDir = $this->freeDict->getCacheDir() . '/' . $pair;
            @\mkdir($pairDir, 0777, true);

            if ($force && \is_file("$pairDir/stardict.tar.xz")) {
                @\unlink("$pairDir/stardict.tar.xz");
            }
            if ($force && \is_dir("$pairDir/stardict")) {
                $this->rrmdir("$pairDir/stardict");
            }

            try {
                $dictDir = $this->freeDict->ensureStarDictReady($pair, $row->bestUrl);
                $res = $this->freeDict->starDictFirstRecord($dictDir);
                if (!($res['found'] ?? false)) {
                    $io->warning("$pair: no entry found.");
                } else {
                    $io->writeln("$pair — {$res['bookname']} — {$res['headword']} : {$res['value_snippet']}");
                }
            } catch (\Throwable $e) {
                $io->warning("$pair: " . $e->getMessage());
            }

            $processed++;
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $io->success("Processed $processed StarDict dictionaries.");
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
