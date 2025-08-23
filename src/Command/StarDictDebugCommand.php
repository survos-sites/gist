<?php
// src/Command/StarDictDebugCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Repository\FreeDictCatalogRepository;
use App\Service\FreeDictService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:stardict:debug', description: 'Debug StarDict index & payload reading for a pair')]
final class StarDictDebugCommand
{
    public function __construct(
        private readonly FreeDictCatalogRepository $repo,
        private readonly FreeDictService $svc,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Language pair slug, e.g. "cat-ita"')]
        string $pair,
        #[Option('How many entries to inspect (default 3)', shortcut: 'L')]
        int $limit = 3,
        #[Option('Index offset bits (32 or 64). Leave empty to auto from .ifo', shortcut: 'B')]
        ?int $offsetBits = null
    ): int {
        $row = $this->repo->findOneBy(['name' => $pair]);
        if (!$row || ($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
            $io->error("No StarDict release for '$pair'. Run app:load.");
            return 1;
        }

        $dir = $this->svc->ensureStarDictReady($pair, $row->bestUrl);
        $ifoPath = $this->svc->findFirst($dir, '/\.ifo$/i');
        if (!$ifoPath) {
            $io->error("Missing .ifo");
            return 1;
        }
        $ifo = $this->svc->parseIfo($ifoPath);
        $bits = $offsetBits ?? (int)($ifo['idxoffsetbits'] ?? 32);

        $bookname = $ifo['bookname'] ?? '(unknown)';
        $version = $ifo['version'] ?? '?';
        $sametypesequence = $ifo['sametypesequence'] ?? '(none)';

        $io->title(sprintf('%s — %s', $pair, $bookname));
        $io->writeln(sprintf('version=%s idxoffsetbits=%d sametypesequence=%s', $version, $bits, $sametypesequence));

        $idxPath       = $this->svc->ensureIdxPath(\dirname($ifoPath));
        $dictDzOrDict  = $this->svc->ensureDictPath(\dirname($ifoPath), false);
        $dictPlain     = $this->svc->ensureDictPath(\dirname($ifoPath), true);

        $io->writeln("idx:  $idxPath");
        $io->writeln("dict: $dictDzOrDict");
        $io->writeln("dict (plain used for debug): $dictPlain");
        $io->newLine();

        $info = \stat($dictPlain);
        $dictSize = $info ? (int)$info['size'] : 0;
        $io->writeln("dict size: " . number_format($dictSize) . " bytes");
        $io->newLine();

        $rows = $this->svc->readIndexEntries($idxPath, $bits, $limit > 0 ? $limit : 1);

        $i = 0;
        foreach ($rows as $r) {
            $i++;
            $io->section("[$i] {$r['headword']}");
            $io->writeln(sprintf(
                "raw BE offset=%s size=%s | BE offset(dec)=%d size=%d",
                $r['off_be_hex'], $r['size_be_hex'], $r['off_be'], $r['size_be']
            ));
            $io->writeln(sprintf(
                "raw LE offset=%s size=%s | LE offset(dec)=%d size=%d",
                $r['off_le_hex'], $r['size_le_hex'], $r['off_le'], $r['size_le']
            ));

            $pick = $this->svc->chooseEndian($dictSize, $r['off_be'], $r['size_be'], $r['off_le'], $r['size_le']);
            $io->writeln("chosen-endian: " . $pick['endian'] . " (bounds=" . ($pick['in_bounds'] ? 'OK' : 'BAD') . ')');

            $payload = $this->svc->readSlice($dictPlain, $pick['offset'], $pick['size'], 512);
            $preview = $this->svc->previewText($payload, 160);
            $isUtf8  = \mb_check_encoding($payload, 'UTF-8');

            $io->writeln("utf8=" . ($isUtf8 ? 'yes' : 'no'));
            $io->writeln("preview: " . $preview);
            $io->writeln("hex dump:");
            $io->writeln($this->svc->hexDump($payload, 64));

            if ($limit > 0 && $i >= $limit) break;
        }

        return 0;
    }
}
