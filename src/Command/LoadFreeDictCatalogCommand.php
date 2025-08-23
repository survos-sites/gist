<?php
// src/Command/LoadFreeDictCatalogCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Entity\FreeDictCatalog;
use App\Repository\FreeDictCatalogRepository;
use App\Service\FreeDictService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:load', description: 'Load the FreeDict JSON into an entity')]
final class LoadFreeDictCatalogCommand
{
    public function __construct(
        private readonly FreeDictService $freeDict,
        private readonly FreeDictCatalogRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Fetch the FreeDict database and upsert the catalog')]
        ?string $noop = null
    ): int {
        $io->title('Loading FreeDict catalog');
        $data = $this->freeDict->fetchCatalog();

        // The modern endpoint returns a dictionary-per-file JSON (like your sample),
        // but some mirrors return { "dictionaries": [...] }.
        $items = $data['dictionaries'] ?? $data ?? null;
        if (!\is_array($items)) {
            $io->error('Unrecognized FreeDict catalog format.');
            return 1;
        }

        $count = 0;
        foreach ($items as $item) {
            if (!\is_array($item)) continue;
            // Required: "name" like 'afr-deu'
            $name = (string)($item['name'] ?? '');
            if ($name === '') continue;

            // Derive src/dst from name
            [$src, $dst] = \array_pad(\explode('-', $name, 2), 2, null);
            if (!$src || !$dst) continue;

            // Prefer stardict release
            [$url, $platform, $rdate, $rver, $rsize] = $this->freeDict->pickBestRelease($item);

            $row = $this->repo->findOneByName($name) ?? new FreeDictCatalog();
            $row->name = $name;
            $row->src = $src;
            $row->dst = $dst;

            $row->edition = \is_string($item['edition'] ?? null) ? $item['edition'] : null;
            $row->headwords = \is_numeric($item['headwords'] ?? null) ? (int)$item['headwords'] : null;
            $row->maintainerName = \is_string($item['maintainerName'] ?? null) ? $item['maintainerName'] : null;
            $row->sourceURL = \is_string($item['sourceURL'] ?? null) ? $item['sourceURL'] : null;
            $row->status = \is_string($item['status'] ?? null) ? $item['status'] : null;

            $row->bestUrl = $url;
            $row->bestPlatform = $platform;
            $row->releaseDate = $rdate ?: null;
            $row->releaseVersion = $rver ?: null;
            $row->releaseSize = $rsize;
            $row->raw = $item;

            if (!$this->em->contains($row)) {
                $this->em->persist($row);
            }
            $count++;
        }

        $this->em->flush();
        $io->success("Upserted $count FreeDict catalog rows.");
        $io->writeln('Try: bin/console app:browse afr-deu');
        return 0;
    }
}
