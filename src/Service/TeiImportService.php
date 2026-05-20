<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Dictionary;
use App\Entity\FreeDictCatalog;
use App\Entity\Language;
use App\Entity\Lemma;
use App\Entity\Sense;
use App\Entity\Translation;
use App\Repository\DictionaryRepository;
use App\Repository\FreeDictCatalogRepository;
use App\Repository\LanguageRepository;
use App\Repository\LemmaRepository;
use App\Repository\TranslationRepository;
use App\Workflow\IFreeDictCatalogWorkflow as WF;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TeiImportService
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%app.data_dir%')] private readonly string $dataDir,
        private readonly Filesystem $fs,
        private readonly ManagerRegistry $doctrine,
        private readonly FreeDictCatalogRepository $catalogRepo,
        private EntityManagerInterface $em,
        private LanguageRepository $langRepo,
        private DictionaryRepository $dictRepo,
        private LemmaRepository $lemmaRepo,
        private TranslationRepository $transRepo,
    ) {}

    // =========================================================================
    // Commands
    // =========================================================================

    #[AsCommand('app:tei:import', 'Import a single FreeDict TEI pair into the database')]
    public function import(
        SymfonyStyle $io,
        #[Argument('Dictionary pair slug, e.g. "eng-spa"')]
        string $pair,
        #[Option('Re-import even if already imported', shortcut: 'f')]
        bool $force = false,
        #[Option('Limit number of entries (for testing)', shortcut: 'L')]
        ?int $limit = null,
    ): int {
        $row = $this->catalogRepo->findOneByName($pair);
        if (!$row) {
            $io->error("No catalog row for '$pair'. Run: bin/console app:load");
            return Command::FAILURE;
        }

        if ($row->marking === WF::PLACE_PROCESSED && !$force) {
            $io->warning("'$pair' already imported. Use --force to reimport.");
            return Command::SUCCESS;
        }

        $count = 0;
        $io->title("TEI import: $pair");

        $dict = $this->importTei(
            $row,
            truncate: $force,
            limit: $limit,
            progress: function (int $n) use (&$count, $io) {
                $count = $n;
                if ($n % 1000 === 0) {
                    $io->writeln("  … $n entries");
                }
            }
        );

        $row->marking = WF::PLACE_PROCESSED;
        $this->em->flush();

        $io->success("Imported $count entries into dictionary {$dict->name}.");
        return Command::SUCCESS;
    }

    #[AsCommand('app:tei:import:all', 'Import all FreeDict TEI pairs not yet imported')]
    public function importAll(
        SymfonyStyle $io,
        #[Option('Force reimport of already-processed pairs', shortcut: 'f')]
        bool $force = false,
        #[Option('Max entries per pair (for testing)', shortcut: 'L')]
        ?int $limit = null,
        #[Option('Max number of pairs to import', shortcut: 'M')]
        ?int $maxPairs = null,
    ): int {
        $io->title('FreeDict: Import All TEI Pairs');

        $candidates = $force
            ? $this->catalogRepo->findAll()
            : $this->catalogRepo->findByMarking(WF::PLACE_NEW);

        if (!$candidates) {
            $io->success('Nothing to import — all pairs already processed. Use --force to reimport.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found %d pair(s) to import.%s', count($candidates), $force ? ' (forcing reimport)' : ''));

        $progress = $io->createProgressBar(count($candidates));
        $progress->start();

        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($candidates as $catalog) {
            [$teiUrl] = $this->pickTeiUrl($catalog);
            if ($teiUrl === '') {
                $io->writeln("\n  Skipping {$catalog->name}: no TEI/src release URL.");
                $skipped++;
                $progress->advance();
                continue;
            }

            try {
                $this->importTei($catalog, truncate: $force, limit: $limit);
                $catalog->marking = WF::PLACE_PROCESSED;
                $this->em->flush();
                $imported++;
            } catch (\Throwable $e) {
                $catalog->marking = WF::PLACE_NEW;
                $this->em->flush();
                $io->writeln("\n  Failed {$catalog->name}: " . $e->getMessage());
                $failed++;
            }

            $progress->advance();

            if ($maxPairs && ($imported + $failed) >= $maxPairs) {
                break;
            }
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Done. Imported: %d, Skipped: %d, Failed: %d', $imported, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // =========================================================================
    // Service methods
    // =========================================================================

    public function cacheDir(): string
    {
        $dir = $this->dataDir . '/tei';
        $this->fs->mkdir($dir);
        return $dir;
    }

    /** Return [url, platform] for the newest TEI-capable release (tei or src). */
    public function pickTeiUrl(FreeDictCatalog $catalog): array
    {
        $releases = $catalog->raw['releases'] ?? [];
        foreach (['tei', 'src'] as $platform) {
            $c = \array_values(\array_filter($releases, fn($r) => ($r['platform'] ?? null) === $platform));
            if ($c) {
                \usort($c, fn($a, $b) => \strcmp((string)$b['date'], (string)$a['date']));
                return [(string)$c[0]['URL'], $platform];
            }
        }
        return ['', ''];
    }

    public function importTei(
        FreeDictCatalog $catalog,
        bool $truncate = false,
        ?int $limit = null,
        ?callable $progress = null,
    ): Dictionary {
        $this->refreshManagers();

        $pair = $catalog->name;
        if ($pair === '' || !\str_contains($pair, '-')) {
            throw new \InvalidArgumentException("Catalog item missing valid name: '$pair'");
        }
        [$srcCode, $dstCode] = \explode('-', $pair, 2);

        $src = $this->langRepo->getOrCreate($srcCode, null, $srcCode);
        $dst = $this->langRepo->getOrCreate($dstCode, null, $dstCode);

        $dict = $this->dictRepo->findOneBy(['name' => $pair]) ?? new Dictionary();
        if (!$this->em->contains($dict)) {
            $this->em->persist($dict);
        }
        $dict->name          = $pair;
        $dict->src           = $src;
        $dict->dst           = $dst;
        $dict->edition       = $catalog->edition;
        $dict->releaseVersion = $catalog->releaseVersion;
        $dict->releaseDate   = $catalog->releaseDate;

        [$teiUrl] = $this->pickTeiUrl($catalog);
        if ($teiUrl === '') {
            throw new \RuntimeException("No TEI URL for $pair.");
        }
        $dict->teiUrl = $teiUrl;

        $this->safeFlush();

        if ($truncate) {
            $conn = $this->em->getConnection();
            $conn->executeStatement(<<<SQL
                DELETE FROM translation
                WHERE src_lemma_id IN (SELECT id FROM lemma WHERE language_id = :src)
                  AND dst_lemma_id IN (SELECT id FROM lemma WHERE language_id = :dst)
                SQL,
                ['src' => $src->id, 'dst' => $dst->id]
            );
        }

        $teiPath = $this->ensureTeiXml($pair, $teiUrl);
        $reader  = new \XMLReader();
        if (!$reader->open($teiPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException("Cannot open TEI: $teiPath");
        }

        $ns    = 'http://www.tei-c.org/ns/1.0';
        $count = 0;
        $batch = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'entry') {
                continue;
            }
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;

            $doc = new \DOMDocument();
            $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('tei', $ns);

            $orth = $this->text($xp, '//tei:form/tei:orth') ?? '';
            if ($orth === '') continue;

            $pos    = $this->text($xp, '//tei:gramGrp/tei:pos');
            $gender = $this->shortGender($this->text($xp, '//tei:gramGrp/tei:gen'));
            $lemma  = $this->lemmaRepo->upsert($src, $orth, $pos, $gender, features: null);

            $rank = 1;
            foreach ($this->texts($xp, '//tei:sense/tei:def | //tei:sense/tei:gloss') as $gloss) {
                $s        = new Sense();
                $s->lemma = $lemma;
                $s->gloss = $this->clean($gloss);
                $s->rank  = $rank++;
                $this->em->persist($s);
            }

            $tRank = 1;
            foreach ($this->texts($xp, '//tei:sense//tei:cit[@type="translation"]//tei:quote') as $tw) {
                $tw = \trim($tw);
                if ($tw === '') continue;
                $tLemma = $this->lemmaRepo->upsert($dst, $tw, null, null, null);
                if (!$this->transRepo->findOneBy(['srcLemma' => $lemma, 'dstLemma' => $tLemma])) {
                    $edge           = new Translation();
                    $edge->srcLemma = $lemma;
                    $edge->dstLemma = $tLemma;
                    $edge->rank     = $tRank++;
                    $this->em->persist($edge);
                }
            }

            $batch++;
            if ($batch % 1000 === 0) {
                try {
                    $this->safeFlush();
                } catch (UniqueConstraintViolationException) {
                    // ignore dup races
                } catch (\Throwable) {
                    // swallow; refreshManagers will reopen EM
                }
                $this->em->clear();
                $this->refreshManagers();
                $src = $this->langRepo->find($src->id) ?? $src;
                $dst = $this->langRepo->find($dst->id) ?? $dst;
            }

            if ($progress) {
                $progress(++$count);
            }
            if ($limit && $count >= $limit) break;
        }

        $reader->close();

        try {
            $this->safeFlush();
        } catch (UniqueConstraintViolationException) {
            // ignore
        }

        return $dict;
    }

    public function ensureTeiXml(string $pair, string $teiUrl): string
    {
        $base = $this->cacheDir() . "/$pair";
        $this->fs->mkdir($base);
        $dest = "$base/tei.xml";

        if (\is_file($dest) && \filesize($dest) > 0) {
            return $dest;
        }

        if (\preg_match('~\.(tei|xml)$~i', $teiUrl)) {
            $this->download($teiUrl, $dest);
            return $dest;
        }

        $archive = "$base/src.tar.xz";
        $this->download($teiUrl, $archive);
        $this->run('tar -xJf ' . \escapeshellarg($archive) . ' -C ' . \escapeshellarg($base));
        $tei = $this->findFirst($base, '/\.(tei|xml)$/i');
        if (!$tei) {
            throw new \RuntimeException("No TEI/XML found inside $archive");
        }
        if ($tei !== $dest) {
            $this->fs->copy($tei, $dest);
        }
        return $dest;
    }

    public function download(string $url, string $dest): void
    {
        $this->fs->mkdir(\dirname($dest));
        $resp = $this->http->request('GET', $url, ['timeout' => 600]);
        if (200 !== $resp->getStatusCode()) {
            throw new \RuntimeException("Download failed ($url): HTTP {$resp->getStatusCode()}");
        }
        $fp = \fopen($dest, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open $dest for writing");
        }
        try {
            foreach ($this->http->stream($resp) as $chunk) {
                if ($chunk->isTimeout()) continue;
                $data = $chunk->getContent();
                if ($data !== '') \fwrite($fp, $data);
            }
        } finally {
            \fclose($fp);
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function refreshManagers(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
        }
        $this->em        = $this->doctrine->getManager();
        $this->langRepo  = $this->em->getRepository(Language::class);
        $this->dictRepo  = $this->em->getRepository(Dictionary::class);
        $this->lemmaRepo = $this->em->getRepository(Lemma::class);
        $this->transRepo = $this->em->getRepository(Translation::class);
    }

    private function safeFlush(): void
    {
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->doctrine->resetManager();
            $this->refreshManagers();
            throw $e;
        }
    }

    private function text(\DOMXPath $xp, string $query): ?string
    {
        $n = $xp->query($query)->item(0);
        return $n ? \trim($n->textContent) : null;
    }

    private function texts(\DOMXPath $xp, string $query): array
    {
        $out = [];
        foreach ($xp->query($query) as $n) {
            $out[] = \trim($n->textContent);
        }
        return $out;
    }

    private function clean(string $s): string
    {
        return \trim(\preg_replace('~\s+~u', ' ', $s) ?? $s);
    }

    private function shortGender(?string $g): ?string
    {
        return match (\mb_strtolower(\trim((string)$g))) {
            'masculine', 'm' => 'm',
            'feminine', 'f'  => 'f',
            'neuter', 'n'    => 'n',
            default          => null,
        };
    }

    private function findFirst(string $base, string $regex): ?string
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $f) {
            if ($f->isFile() && \preg_match($regex, $f->getFilename())) {
                return $f->getPathname();
            }
        }
        return null;
    }

    private function run(string $cmd): void
    {
        $p = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($p)) {
            throw new \RuntimeException("Failed to launch: $cmd");
        }
        $err  = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($p);
        if ($code !== 0) {
            throw new \RuntimeException("Command failed ($code): $cmd\n$err");
        }
    }
}
