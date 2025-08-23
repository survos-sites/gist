<?php
// src/Command/TeiImportAllCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Repository\FreeDictCatalogRepository;
use App\Service\TeiImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tei:import:all', description: 'Import TEI for all catalog pairs that publish TEI/src')]
final class TeiImportAllCommand
{
    public function __construct(
        private readonly FreeDictCatalogRepository $catalog,
        private readonly TeiImportService $svc,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Import all TEI-capable dictionaries (ignores pairs without TEI/src)')]
        ?string $noop = null,
        #[Option('Force reimport (truncate edges) for each pair', shortcut: 'f')]
        bool $force = false,
        #[Option('Limit number of entries per pair (testing)', shortcut: 'L')]
        ?int $limit = null,
        #[Option('Max number of pairs to import (0 = no limit)', shortcut: 'M')]
        int $maxPairs = 0
    ): int {
        $rows = $this->catalog->findBy([], ['name' => 'ASC']);
        $countPairs = 0;
        $importedPairs = 0;

        foreach ($rows as $row) {
            $countPairs++;
            $raw = $row->raw ?? null;
            if (!$raw || !\is_array($raw)) {
                continue;
            }
            [$url, $platform] = $this->svc->pickTeiUrl($raw);
            if ($url === '') {
                continue;
            }

            $io->title("Importing TEI for {$row->name} ($platform)");
            try {
                $this->svc->importTei($raw, truncate: $force, limit: $limit, progress: function (int $n) use ($io) {
                    if ($n % 1000 === 0) $io->writeln("  … $n entries");
                });
                $importedPairs++;
            } catch (\Throwable $e) {
                $io->warning("  Skipped {$row->name}: " . $e->getMessage());
            }

            if ($maxPairs && ($importedPairs >= $maxPairs)) {
                break;
            }
        }

        $io->success("Imported $importedPairs pair(s) (scanned $countPairs).");
        return 0;
    }
}
