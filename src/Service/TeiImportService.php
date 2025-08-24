<?php
// src/Service/TeiImportService.php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Dictionary;
use App\Entity\FreeDictCatalog;
use App\Entity\Language;
use App\Entity\Lemma;
use App\Entity\Sense;
use App\Entity\Translation;
use App\Repository\DictionaryRepository;
use App\Repository\LanguageRepository;
use App\Repository\LemmaRepository;
use App\Repository\TranslationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TeiImportService
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly Filesystem $fs,
        private readonly ManagerRegistry $doctrine,
        private EntityManagerInterface $em,
        private LanguageRepository $langRepo,
        private DictionaryRepository $dictRepo,
        private LemmaRepository $lemmaRepo,
        private TranslationRepository $transRepo,
    ) {}

    public function cacheDir(): string
    {
        $dir = $this->projectDir . '/data/tei';
        $this->fs->mkdir($dir);
        return $dir;
    }

    private function refreshManagers(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
        }
        $this->em = $this->doctrine->getManager();
        $this->langRepo = $this->em->getRepository(Language::class);
        $this->dictRepo = $this->em->getRepository(Dictionary::class);
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

    public function pickTeiUrl(FreeDictCatalog $catalog): array
    {
        $releases = $catalog->raw['releases'] ?? [];
        foreach (['tei', 'src'] as $platform) {
            $c = \array_values(\array_filter($releases, fn($r) => ($r['platform'] ?? null) === $platform));
            if ($c) {
                \usort($c, fn($a,$b) => \strcmp((string)$b['date'], (string)$a['date']));
                $r = $c[0];
                return [(string)$r['URL'], (string)$platform];
            }
        }
        return ['', ''];
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
        $this->run("tar -xJf " . \escapeshellarg($archive) . ' -C ' . \escapeshellarg($base));
        $tei = $this->findFirst($base, '/\.(tei|xml)$/i');
        if (!$tei) {
            throw new \RuntimeException("No TEI/XML found inside $archive");
        }
        if ($tei !== $dest) {
            @\copy($tei, $dest);
        }
        return $dest;
    }

    public function download(string $url, string $dest): void
    {
        $this->fs->mkdir(\dirname($dest));
        $resp = $this->http->request('GET', $url, ['timeout' => 600]);
        if (200 !== $resp->getStatusCode()) {
            throw new \RuntimeException("Download failed: $url");
        }
        $fp = \fopen($dest, 'wb');
        if (!$fp) {
            throw new \RuntimeException("Cannot open $dest");
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

    private function run(string $cmd): void
    {
        $p = \proc_open($cmd, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!\is_resource($p)) throw new \RuntimeException("Failed: $cmd");
        $err = \stream_get_contents($pipes[2]); \fclose($pipes[2]); \fclose($pipes[1]);
        $code = \proc_close($p);
        if ($code !== 0) throw new \RuntimeException("Command failed ($code): $cmd\n$err");
    }

    private function findFirst(string $base, string $regex): ?string
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile() && \preg_match($regex, $f->getFilename())) {
                return $f->getPathname();
            }
        }
        return null;
    }

    public function importTei(FreeDictCatalog $catalog, bool $truncate = false, ?int $limit = null, ?callable $progress = null): Dictionary
    {
        $this->refreshManagers(); /// ?

        $pair = $catalog->name;
        if ($pair === '' || !\str_contains($pair, '-')) {
            throw new \InvalidArgumentException('Catalog item missing name (pair).');
        }
        [$srcCode, $dstCode] = \explode('-', $pair, 2);

        $src = $this->langRepo->getOrCreate($srcCode, null, $srcCode);
        $dst = $this->langRepo->getOrCreate($dstCode, null, $dstCode);

        if (!$dict = $this->dictRepo->findOneBy(['name' => $pair])) {
            $dict = new Dictionary();
            $this->em->persist($dict);
        }
        $dict->name = $pair;
        $dict->src = $src;
        $dict->dst = $dst;
        $dict->edition = (string)($catalogItem['edition'] ?? '') ?: null;
        $dict->releaseVersion = (string)($catalogItem['edition'] ?? '') ?: null;

        [$teiUrl] = $this->pickTeiUrl($catalog);
        if ($teiUrl === '') {
            throw new \RuntimeException("No TEI URL for $pair.");
        }
        $dict->teiUrl = $teiUrl;

        $this->safeFlush();

        // 🔁 PORTABLE TRUNCATE (SQLite + Postgres)
        if ($truncate) {
            $conn = $this->em->getConnection();
            $sql = <<<SQL
                DELETE FROM translation
                WHERE src_lemma_id IN (
                    SELECT ls.id FROM lemma ls WHERE ls.language_id = :src
                )
                  AND dst_lemma_id IN (
                    SELECT ld.id FROM lemma ld WHERE ld.language_id = :dst
                )
            SQL;
            $conn->executeStatement($sql, ['src' => $src->id, 'dst' => $dst->id]);
        }

        $teiPath = $this->ensureTeiXml($pair, $teiUrl);

        $reader = new \XMLReader();
        if (!$reader->open($teiPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException("Cannot open TEI: $teiPath");
        }

        $ns = 'http://www.tei-c.org/ns/1.0';
        $count = 0;
        $batch = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'entry') {
                continue;
            }
            $xml = $reader->readOuterXML();
            if ($xml === '') continue;

            $entry = new \DOMDocument();
            $entry->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xp = new \DOMXPath($entry);
            $xp->registerNamespace('tei', $ns);

            $orth = $this->text($xp, '//tei:form/tei:orth') ?? '';
            if ($orth === '') continue;

            $pos    = $this->text($xp, '//tei:gramGrp/tei:pos') ?? null;
            $gender = $this->shortGender($this->text($xp, '//tei:gramGrp/tei:gen'));

            $lemma = $this->lemmaRepo->upsert($src, $orth, $pos, $gender, features: null);

            $senseTexts = $this->texts($xp, '//tei:sense/tei:def | //tei:sense/tei:gloss');
            $rank = 1;
            foreach ($senseTexts as $gloss) {
                $s = new \App\Entity\Sense();
                $s->lemma = $lemma;
                $s->gloss = $this->clean($gloss);
                $s->rank = $rank++;
                $this->em->persist($s);
            }

            $targetWords = $this->texts($xp, '//tei:sense//tei:cit[@type="translation"]//tei:quote');
            $tRank = 1;
            foreach ($targetWords as $tw) {
                $tw = \trim($tw);
                if ($tw === '') continue;
                $tLemma = $this->lemmaRepo->upsert($dst, $tw, null, null, null);
                dd($tLemma);

                $exists = $this->transRepo->findOneBy(['srcLemma' => $lemma, 'dstLemma' => $tLemma]);
                if (!$exists) {
                    $edge = new Translation();
                    $edge->srcLemma = $lemma;
                    $edge->dstLemma = $tLemma;
                    $edge->rank = $tRank++;
                    $this->em->persist($edge);
                }
            }

            $batch++;
            if ($batch % 1000 === 0) {
                try { $this->safeFlush(); }
                catch (UniqueConstraintViolationException) { /* ignore dup races */ }
                catch (\Throwable) { /* logged upstream if needed */ }

                $this->em->clear();
                $this->refreshManagers();
                $src = $this->langRepo->find($src->id) ?? $src;
                $dst = $this->langRepo->find($dst->id) ?? $dst;
            }

            if ($progress) { $progress(++$count); }
            if ($limit && $count >= $limit) break;
        }

        $reader->close();

        try { $this->safeFlush(); }
        catch (UniqueConstraintViolationException) { /* ignore */ }

        return $dict;
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
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        return \trim($s);
    }
    private function shortGender(?string $g): ?string
    {
        $g = $g ? \mb_strtolower(\trim($g)) : null;
        return match ($g) {
            'masculine','m' => 'm',
            'feminine','f'  => 'f',
            'neuter','n'    => 'n',
            default         => null,
        };
    }
}
