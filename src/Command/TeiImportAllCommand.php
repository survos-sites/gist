<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\FreeDictCatalog;
use App\Repository\FreeDictCatalogRepository;
use App\Service\TeiImportService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\InvokableCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\Option;

#[AsCommand(name: 'app:tei:import:all', description: 'Import all FreeDict TEI pairs that are not yet imported.')]
class TeiImportAllCommand extends InvokableCommand
{
    public function __construct(
        private FreeDictCatalogRepository $catalogRepo,
        private TeiImportService $importer,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('force')] bool $force = false,
        #[Option('limit')] ?int $limit = null,
        #[Option('max-pairs')] ?int $maxPairs = null,
        InputInterface $input = null,
        OutputInterface $output = null,
    ): int {
        $io->title('FreeDict: Import All');

        $limit ??= 0; // 0 = no limit
        $candidates = $this->catalogRepo->findImportCandidates(limit: $limit);

        if (!$candidates) {
            $io->success('No catalogs found.');
            return self::SUCCESS;
        }

        $io->writeln(sprintf('Found %d catalog(s).%s', count($candidates), $force ? ' (forcing reimport)' : ''));
        $progress = $io->createProgressBar(count($candidates));
        $progress->start();

        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($candidates as $catalog) {
            \assert($catalog instanceof FreeDictCatalog);

            if (!$force && $catalog->importedAt) {
                $skipped++;
                $progress->advance();
                continue;
            }

            try {
                $teiUrl = $this->importer->pickTeiUrl($catalog);
                $stats  = $this->importer->importPair($catalog, $teiUrl, $maxPairs);

                // Mark as imported (or at least “attempted”)
                $catalog->importedAt = new DateTimeImmutable();
                $catalog->importStatus = 'imported';
                $catalog->importMessage = sprintf('ok: %s', json_encode($stats, JSON_UNESCAPED_SLASHES));

                $imported++;
            } catch (\Throwable $e) {
                $catalog->importStatus = 'failed';
                $catalog->importMessage = $e->getMessage();
                $failed++;
            }

            // Flush in small batches to keep memory low
            $this->catalogRepo->save($catalog, true);
            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf('Done. Imported: %d, Skipped: %d, Failed: %d', $imported, $skipped, $failed));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
