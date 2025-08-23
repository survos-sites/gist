<?php
// src/Command/TeiImportCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Repository\FreeDictCatalogRepository; // if you're still storing the catalog; else pass JSON another way
use App\Service\TeiImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tei:import', description: 'Import a FreeDict TEI dictionary into Postgres')]
final class TeiImportCommand
{
    public function __construct(
        private readonly FreeDictCatalogRepository $catalog,
        private readonly TeiImportService $svc,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dictionary pair, e.g. "eng-spa"')]
        string $pair,
        #[Option('Re-download/replace existing DB content for this pair', shortcut: 'f')]
        bool $force = false,
        #[Option('Limit number of entries (for testing)', shortcut: 'L')]
        ?int $limit = null
    ): int {
        $row = $this->catalog->findOneBy(['name' => $pair]);
        if (!$row) {
            $io->error("No catalog row for '$pair'. Run: bin/console app:load");
            return 1;
        }
        if (!$row->raw || !\is_array($row->raw)) {
            $io->error('Catalog row missing raw JSON data.');
            return 1;
        }

        $count = 0;
        $io->title("TEI import for $pair");
        $dict = $this->svc->importTei(
            $row->raw,
            truncate: $force,
            limit: $limit,
            progress: function (int $n) use (&$count, $io) {
                $count = $n;
                if ($n % 1000 === 0) $io->writeln("Imported $n entries…");
            }
        );

        $io->success("Imported $count entries into dictionary {$dict->name}.");
        return 0;
    }
}
