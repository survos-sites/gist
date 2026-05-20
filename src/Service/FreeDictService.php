<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FreeDictCatalog;
use App\Repository\FreeDictCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use StarDict\StarDict;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FreeDictService
{
    public const CATALOG_URL = 'https://freedict.org/freedict-database.json';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        #[Autowire('%app.data_dir%')] private readonly string $dataDir,
        private readonly FreeDictCatalogRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    // =========================================================================
    // Commands
    // =========================================================================

    #[AsCommand('app:load', 'Load the FreeDict catalog JSON into the database')]
    public function load(
        SymfonyStyle $io,
        #[Option('Truncate existing catalog rows before loading')]
        bool $reset = false,
    ): int {
        $io->title('Loading FreeDict catalog');

        if ($reset) {
            foreach ($this->repo->findAll() as $row) {
                $this->em->remove($row);
            }
            $this->em->flush();
        }

        $data = $this->fetchCatalog();
        $items = $data['dictionaries'] ?? $data ?? null;
        if (!\is_array($items)) {
            $io->error('Unrecognized FreeDict catalog format.');

            return Command::FAILURE;
        }

        $count = 0;
        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            [$src, $dst] = \array_pad(\explode('-', $name, 2), 2, null);
            if (!$src || !$dst) {
                continue;
            }

            [$url, $platform, $rdate, $rver, $rsize] = $this->pickBestRelease($item);

            $row = $this->repo->findOneByName($name) ?? new FreeDictCatalog($name);
            $row->src = $src;
            $row->dst = $dst;
            $row->edition = \is_string($item['edition'] ?? null) ? $item['edition'] : null;
            $row->headwords = \is_numeric($item['headwords'] ?? null) ? (int) $item['headwords'] : null;
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
            ++$count;
        }

        $this->em->flush();
        $io->success("Upserted $count FreeDict catalog rows.");
        $io->writeln('Try: bin/console app:browse afr-deu');

        return Command::SUCCESS;
    }

    #[AsCommand('app:browse', 'Browse a single dictionary pair (downloads/extracts if needed)')]
    public function browse(
        SymfonyStyle $io,
        #[Argument('Dictionary pair slug, e.g. "eng-deu"')]
        string $pair,
        #[Option('Force re-download/extract even if cached locally', shortcut: 'f')]
        bool $force = false,
        #[Option('Output format: text|json', shortcut: 'F')]
        string $format = 'text',
    ): int {
        $io->title("Browsing FreeDict: $pair");

        $row = $this->repo->findOneBy(['name' => $pair]);
        if (!$row) {
            $io->error("No catalog row for '$pair'. Run: bin/console app:load");

            return Command::FAILURE;
        }

        if (($row->bestPlatform ?? '') !== 'stardict') {
            $io->warning("'$pair' has no StarDict release (bestPlatform={$row->bestPlatform}).");

            return Command::SUCCESS;
        }

        if (!$row->bestUrl) {
            $io->warning("'$pair' has no StarDict URL recorded.");

            return Command::SUCCESS;
        }

        if ($force) {
            $this->clearPairCache($pair);
        }

        $io->writeln('Ensuring StarDict files are present…');
        $dictDir = $this->ensureStarDictReady($pair, $row->bestUrl);

        $io->writeln('Reading first record…');
        $detailed = $this->starDictFirstRecordDetailed($dictDir);

        if (!($detailed['found'] ?? false)) {
            $io->warning($detailed['message'] ?? 'No entry found.');

            return Command::SUCCESS;
        }

        if ('json' === \strtolower($format)) {
            $io->writeln(\json_encode([
                'pair' => $pair,
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
                'value_raw' => $detailed['value_raw'] ?? null,
                'value_text' => $detailed['value_text'] ?? null,
                'value_preview' => $detailed['value_preview'] ?? null,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_IGNORE));

            return Command::SUCCESS;
        }

        $io->section('Dictionary');
        $io->writeln(($detailed['ifo']['bookname'] ?? $pair).'  ['.($detailed['reader'] ?? 'unknown').']');
        $io->section('Headword');
        $io->writeln((string) ($detailed['headword'] ?? '(unknown)'));
        $io->section('Value (snippet)');
        $io->writeln((string) ($detailed['value_preview'] ?? '(empty)'));

        return Command::SUCCESS;
    }

    #[AsCommand('app:browse:all', 'Loop through all StarDict dictionaries and show the first entry')]
    public function browseAll(
        SymfonyStyle $io,
        #[Option('Limit number of dictionaries to process (0 = all)', shortcut: 'l')]
        int $limit = 10,
        #[Option('Force re-download/extract even if cached locally', shortcut: 'f')]
        bool $force = false,
    ): int {
        $rows = $this->repo->findBy([], ['name' => 'ASC']);
        $processed = 0;

        foreach ($rows as $row) {
            if (($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
                continue;
            }

            if ($force) {
                $this->clearPairCache($row->name);
            }

            $io->title("Browsing {$row->name}");

            try {
                $dictDir = $this->ensureStarDictReady($row->name, $row->bestUrl);
                $res = $this->starDictFirstRecord($dictDir);
                if (!($res['found'] ?? false)) {
                    $io->warning("{$row->name}: no entry found.");
                } else {
                    $io->writeln("{$row->name} — {$res['bookname']} — {$res['headword']} : {$res['value_snippet']}");
                }
            } catch (\Throwable $e) {
                $io->warning("{$row->name}: ".$e->getMessage());
            }

            ++$processed;
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $io->success("Processed $processed StarDict dictionaries.");

        return Command::SUCCESS;
    }

    // =========================================================================
    // Service methods
    // =========================================================================

    public function getCacheDir(): string
    {
        $this->fs->mkdir($this->dataDir);

        return $this->dataDir;
    }

    public function clearPairCache(string $pair): void
    {
        $pairDir = $this->getCacheDir().'/'.$pair;
        $this->fs->remove("$pairDir/stardict.tar.xz");
        if (\is_dir("$pairDir/stardict")) {
            $this->fs->remove("$pairDir/stardict");
        }
    }

    public function fetchCatalog(): array
    {
        return $this->cache->get('cat', function (CacheItemInterface $item) {
            $resp = $this->http->request('GET', self::CATALOG_URL, ['timeout' => 60]);
            if (200 !== $resp->getStatusCode()) {
                throw new \RuntimeException('Failed to fetch FreeDict catalog JSON.');
            }

            return $resp->toArray();
        });
    }

    public function pickBestRelease(array $item, bool $stardictOnly = false): array
    {
        $releases = $item['releases'] ?? [];
        if (!\is_array($releases) || [] === $releases) {
            throw new \RuntimeException('No releases[] found on item.');
        }
        $pref = $stardictOnly ? ['stardict'] : ['stardict', 'dictd', 'src', 'slob'];
        foreach ($pref as $platform) {
            $filtered = \array_values(\array_filter($releases, fn ($r) => ($r['platform'] ?? null) === $platform));
            if ($filtered) {
                \usort($filtered, fn ($a, $b) => \strcmp((string) $b['date'], (string) $a['date']));
                $r = $filtered[0];

                return [
                    (string) $r['URL'],
                    (string) $platform,
                    (string) ($r['date'] ?? ''),
                    (string) ($r['version'] ?? ''),
                    \is_numeric($r['size'] ?? null) ? (int) $r['size'] : null,
                ];
            }
        }
        if ($stardictOnly) {
            return ['', '', '', '', null];
        }
        $r = $releases[0];

        return [
            (string) $r['URL'],
            (string) ($r['platform'] ?? 'unknown'),
            (string) ($r['date'] ?? ''),
            (string) ($r['version'] ?? ''),
            \is_numeric($r['size'] ?? null) ? (int) $r['size'] : null,
        ];
    }

    public function downloadTo(string $url, string $dest): string
    {
        $this->fs->mkdir(\dirname($dest));
        $response = $this->http->request('GET', $url, ['timeout' => 600]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Failed to download: $url");
        }
        $fp = \fopen($dest, 'wb');
        if (false === $fp) {
            throw new \RuntimeException("Cannot open $dest for writing.");
        }
        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $data = $chunk->getContent();
                if ('' !== $data) {
                    \fwrite($fp, $data);
                }
            }
        } finally {
            \fclose($fp);
        }

        return $dest;
    }

    public function ensureStarDictReady(string $pair, string $tarXzUrl): string
    {
        $pairDir = $this->getCacheDir()."/$pair";
        $this->fs->mkdir($pairDir);
        $archivePath = "$pairDir/stardict.tar.xz";
        if (!\is_file($archivePath) || 0 === \filesize($archivePath)) {
            $this->downloadTo($tarXzUrl, $archivePath);
        }
        $extractDir = "$pairDir/stardict";
        $this->fs->mkdir($extractDir);
        if (!$this->dirHasFiles($extractDir)) {
            $this->runShell(
                'tar -xJf '.\escapeshellarg($archivePath).' -C '.\escapeshellarg($extractDir),
                'Ensure system tar supports -J (xz). On Debian/Ubuntu: apt-get install xz-utils'
            );
        }
        $ifo = $this->findFirst($extractDir, '/\.ifo$/i');
        $idxAny = $this->findFirst($extractDir, '/\.idx(\.gz)?$/i');
        $dictAny = $this->findFirst($extractDir, '/\.dict(\.dz)?$/i');
        if (!$ifo || !$idxAny || !$dictAny) {
            throw new \RuntimeException("StarDict files not found in $extractDir");
        }

        return \dirname($ifo);
    }

    /** Return a brief summary of the first StarDict record (fast path). */
    public function starDictFirstRecord(string $dir): array
    {
        $ifoPath = $this->findFirst($dir, '/\.ifo$/i');
        if (!$ifoPath) {
            throw new \RuntimeException("Missing .ifo in $dir");
        }
        $ifo = $this->parseIfo($ifoPath);
        $version = $ifo['version'] ?? '';
        $idxOffsetBits = (int) ($ifo['idxoffsetbits'] ?? 32);
        $bookname = $ifo['bookname'] ?? \basename($dir);

        if ('2.4.2' === $version && 32 === $idxOffsetBits) {
            try {
                $idxPath = $this->ensureIdxPath(\dirname($ifoPath));
                $dictAny = $this->ensureDictPath(\dirname($ifoPath), false);
                $dict = StarDict::createFromFiles($ifoPath, $idxPath, $dictAny);
                $meta = $dict->getDict();
                $it = $dict->getIndex()->getIterator();
                $firstKey = null;
                foreach ($it as $k => $v) {
                    $firstKey = (string) $k;
                    break;
                }
                if (null === $firstKey) {
                    return ['found' => false, 'message' => 'Index is empty'];
                }
                $results = $dict->get($firstKey);
                $firstVal = null;
                foreach ($results as $r) {
                    $firstVal = $r->getValue();
                    break;
                }

                return [
                    'found' => true,
                    'bookname' => $meta->getBookname() ?: $bookname,
                    'headword' => $firstKey,
                    'value_snippet' => \mb_substr(\preg_replace('~\s+~u', ' ', \trim(\strip_tags((string) $firstVal))) ?? (string) $firstVal, 0, 400),
                    'reader' => 'skoro',
                ];
            } catch (\Throwable) {
                // fall through to manual path
            }
        }

        $idxPath = $this->ensureIdxPath(\dirname($ifoPath));
        $dictPath = $this->ensureDictPath(\dirname($ifoPath), true);
        $info = \stat($dictPath);
        $dictSize = $info ? (int) $info['size'] : 0;
        $rows = $this->readIndexEntries($idxPath, $idxOffsetBits, 1);
        if ([] === $rows) {
            return ['found' => false, 'message' => 'Index empty'];
        }
        $r = $rows[0];
        $pick = $this->chooseEndian($dictSize, $r['off_be'], $r['size_be'], $r['off_le'], $r['size_le']);
        $payload = $this->readSlice($dictPath, $pick['offset'], $pick['size'], 2048);

        return [
            'found' => true,
            'bookname' => $bookname,
            'headword' => $r['headword'],
            'value_snippet' => $this->previewText($payload, 400),
            'reader' => 'manual-'.$pick['endian'],
        ];
    }

    /** Extended record detail — includes ifo, paths, raw bytes, offsets. Used by browse --format=json. */
    public function starDictFirstRecordDetailed(string $dir): array
    {
        $ifoPath = $this->findFirst($dir, '/\.ifo$/i');
        if (!$ifoPath) {
            return ['found' => false, 'message' => "Missing .ifo in $dir"];
        }
        $ifo = $this->parseIfo($ifoPath);
        $idxOffsetBits = (int) ($ifo['idxoffsetbits'] ?? 32);

        $idxPath = $this->ensureIdxPath(\dirname($ifoPath));
        $dictDzPath = $this->ensureDictPath(\dirname($ifoPath), false);
        $dictPath = $this->ensureDictPath(\dirname($ifoPath), true);
        $info = \stat($dictPath);
        $dictSize = $info ? (int) $info['size'] : 0;

        $rows = $this->readIndexEntries($idxPath, $idxOffsetBits, 1);
        if ([] === $rows) {
            return ['found' => false, 'message' => 'Index empty'];
        }
        $r = $rows[0];
        $pick = $this->chooseEndian($dictSize, $r['off_be'], $r['size_be'], $r['off_le'], $r['size_le']);
        $raw = $this->readSlice($dictPath, $pick['offset'], $pick['size'], 4096);

        $isUtf8 = \mb_check_encoding($raw, 'UTF-8');
        $text = $isUtf8 ? $raw : \mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $preview = $this->previewText($raw, 400);

        return [
            'found' => true,
            'ifo' => $ifo,
            'paths' => [
                'ifo' => $ifoPath,
                'idx' => $idxPath,
                'dict' => $dictDzPath,
                'dict_plain' => $dictPath,
            ],
            'dict_size' => $dictSize,
            'reader' => 'manual-'.$pick['endian'],
            'headword' => $r['headword'],
            'offsets' => [
                'off_be' => $r['off_be'], 'size_be' => $r['size_be'],
                'off_le' => $r['off_le'], 'size_le' => $r['size_le'],
                'chosen' => $pick,
            ],
            'value_raw' => \bin2hex(\substr($raw, 0, 64)).(strlen($raw) > 64 ? '…' : ''),
            'value_text' => \substr($text, 0, 2000),
            'value_preview' => $preview,
        ];
    }

    // =========================================================================
    // Low-level StarDict helpers (used by StarDictDebugCommand → now inline)
    // =========================================================================

    public function parseIfo(string $ifoPath): array
    {
        $out = [];
        $lines = \file($ifoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return $out;
        }
        foreach ($lines as $line) {
            if (!\str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = \explode('=', $line, 2);
            $out[\trim($k)] = \trim($v);
        }

        return $out;
    }

    public function ensureIdxPath(string $dir): string
    {
        $idx = $this->findFirst($dir, '/\.idx$/i');
        if ($idx) {
            return $idx;
        }
        $idxGz = $this->findFirst($dir, '/\.idx\.gz$/i');
        if ($idxGz) {
            return $this->gunzipToSibling($idxGz);
        }
        throw new \RuntimeException("Missing .idx / .idx.gz in $dir");
    }

    public function ensureDictPath(string $dir, bool $needPlain): string
    {
        $dict = $this->findFirst($dir, '/\.dict$/i');
        if ($dict) {
            if ($needPlain && $this->isGzipFile($dict)) {
                return $this->gunzipToFresh($dict, $dict.'.unz');
            }

            return $dict;
        }
        $dictDz = $this->findFirst($dir, '/\.dict\.dz$/i');
        if (!$dictDz) {
            throw new \RuntimeException("Missing .dict / .dict.dz in $dir");
        }

        return $needPlain ? $this->gunzipToSibling($dictDz) : $dictDz;
    }

    public function readIndexEntries(string $idxPath, int $idxOffsetBits, int $limit): array
    {
        $out = [];
        $f = \fopen($idxPath, 'rb');
        if (false === $f) {
            throw new \RuntimeException("Cannot open $idxPath");
        }
        try {
            for ($i = 0; $i < ($limit ?: 1); ++$i) {
                $head = '';
                while (!\feof($f)) {
                    $ch = \fread($f, 1);
                    if ('' === $ch || false === $ch) {
                        break;
                    }
                    if (0 === \ord($ch)) {
                        break;
                    }
                    $head .= $ch;
                    if (\strlen($head) > 4000) {
                        break;
                    }
                }
                if ('' === $head) {
                    break;
                }

                if (64 === $idxOffsetBits) {
                    $ob = \fread($f, 8);
                    $sb = \fread($f, 4);
                    if (false === $ob || false === $sb) {
                        break;
                    }
                    $off_be = $this->uInt64be($ob);
                    $size_be = $this->uInt32be($sb);
                    $off_le = $this->uInt64le($ob);
                    $size_le = $this->uInt32le($sb);
                } else {
                    $ob = \fread($f, 4);
                    $sb = \fread($f, 4);
                    if (false === $ob || false === $sb) {
                        break;
                    }
                    $off_be = $this->uInt32be($ob);
                    $size_be = $this->uInt32be($sb);
                    $off_le = $this->uInt32le($ob);
                    $size_le = $this->uInt32le($sb);
                }

                $out[] = [
                    'headword' => $head,
                    'off_be' => $off_be,  'size_be' => $size_be,
                    'off_le' => $off_le,  'size_le' => $size_le,
                    'off_be_hex' => '0x'.\strtoupper(\bin2hex($ob)),
                    'size_be_hex' => '0x'.\strtoupper(\bin2hex($sb)),
                    'off_le_hex' => '0x'.\strtoupper(\bin2hex(\strrev($ob))),
                    'size_le_hex' => '0x'.\strtoupper(\bin2hex(\strrev($sb))),
                ];
            }
        } finally {
            \fclose($f);
        }

        return $out;
    }

    public function chooseEndian(int $dictSize, int $offBE, int $sizeBE, int $offLE, int $sizeLE): array
    {
        $inBE = $offBE >= 0 && $sizeBE >= 0 && ($offBE + $sizeBE) <= $dictSize;
        $inLE = $offLE >= 0 && $sizeLE >= 0 && ($offLE + $sizeLE) <= $dictSize;
        if ($inBE && !$inLE) {
            return ['endian' => 'BE', 'offset' => $offBE, 'size' => $sizeBE, 'in_bounds' => true];
        }
        if ($inLE && !$inBE) {
            return ['endian' => 'LE', 'offset' => $offLE, 'size' => $sizeLE, 'in_bounds' => true];
        }
        if ($inBE && $inLE) {
            return ['endian' => 'BE', 'offset' => $offBE, 'size' => $sizeBE, 'in_bounds' => true];
        }

        return ['endian' => 'BE', 'offset' => max(0, $offBE), 'size' => max(0, $sizeBE), 'in_bounds' => false];
    }

    public function readSlice(string $file, int $offset, int $size, int $maxLen = 2048): string
    {
        $f = \fopen($file, 'rb');
        if (false === $f) {
            return '';
        }
        try {
            \fseek($f, $offset);
            $n = max(0, min($size, $maxLen));

            return $n > 0 ? (string) \fread($f, $n) : '';
        } finally {
            \fclose($f);
        }
    }

    public function previewText(string $bytes, int $maxChars = 200): string
    {
        if ('' === $bytes) {
            return '(empty)';
        }
        $txt = \preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $bytes) ?? $bytes;
        if (!\mb_check_encoding($txt, 'UTF-8')) {
            $txt = \mb_convert_encoding($txt, 'UTF-8', 'ISO-8859-1');
        }
        $txt = \trim(\strip_tags($txt));
        $txt = \preg_replace('~\s+~u', ' ', $txt) ?? $txt;

        return \mb_substr($txt, 0, $maxChars);
    }

    public function hexDump(string $bytes, int $len = 64): string
    {
        $slice = \substr($bytes, 0, $len);
        $hex = \strtoupper(\bin2hex($slice));
        $out = [];
        for ($i = 0; $i < \strlen($hex); $i += 32) {
            $out[] = \substr($hex, $i, 32);
        }

        return \implode("\n", $out);
    }

    public function findFirst(string $base, string $regex): ?string
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile() && \preg_match($regex, $file->getFilename())) {
                return $file->getPathname();
            }
        }

        return null;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function isGzipFile(string $path): bool
    {
        $fh = \fopen($path, 'rb');
        if (false === $fh) {
            return false;
        }
        $sig = \fread($fh, 3);
        \fclose($fh);

        return "\x1F\x8B\x08" === $sig;
    }

    private function gunzipToSibling(string $gzOrDzPath): string
    {
        $dest = \preg_replace('~\.(gz|dz)$~i', '', $gzOrDzPath);
        if (null === $dest) {
            throw new \RuntimeException("Bad compressed filename: $gzOrDzPath");
        }

        return $this->gunzipToFresh($gzOrDzPath, $dest);
    }

    private function gunzipToFresh(string $compressedPath, string $dest): string
    {
        if (\is_file($dest) && \filesize($dest) > 0) {
            return $dest;
        }
        $in = \gzopen($compressedPath, 'rb');
        if (false === $in) {
            throw new \RuntimeException("gzopen failed for $compressedPath");
        }
        $this->fs->mkdir(\dirname($dest));
        $out = \fopen($dest, 'wb');
        if (false === $out) {
            \gzclose($in);
            throw new \RuntimeException("Cannot open $dest for writing");
        }
        try {
            while (!\gzeof($in)) {
                $buf = \gzread($in, 8192);
                if (false === $buf) {
                    throw new \RuntimeException("gzread error on $compressedPath");
                }
                if ('' !== $buf) {
                    \fwrite($out, $buf);
                }
            }
        } finally {
            \gzclose($in);
            \fclose($out);
        }

        return $dest;
    }

    private function dirHasFiles(string $dir): bool
    {
        $it = \scandir($dir);
        if (false === $it) {
            return false;
        }
        foreach ($it as $name) {
            if ('.' !== $name && '..' !== $name) {
                return true;
            }
        }

        return false;
    }

    private function runShell(string $cmd, string $errorHint = ''): void
    {
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException("Failed to launch: $cmd");
        }
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        if (0 !== $code) {
            $msg = "Command failed ($code): $cmd\nSTDERR: $stderr";
            if ('' !== $errorHint) {
                $msg .= "\nHINT: $errorHint";
            }
            throw new \RuntimeException($msg);
        }
    }

    private function uInt32be(string $bytes): int
    {
        $bytes = (4 === \strlen($bytes)) ? $bytes : ($bytes."\0\0\0\0");
        $arr = \unpack('Nn', \substr($bytes, 0, 4));

        return (int) ($arr['n'] ?? 0);
    }

    private function uInt32le(string $bytes): int
    {
        $bytes = (4 === \strlen($bytes)) ? $bytes : ($bytes."\0\0\0\0");
        $arr = \unpack('Vn', \substr($bytes, 0, 4));

        return (int) ($arr['n'] ?? 0);
    }

    private function uInt64be(string $bytes): int
    {
        $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT);
        $hi = \unpack('Nn', \substr($bytes, 0, 4))['n'] ?? 0;
        $lo = \unpack('Nn', \substr($bytes, 4, 4))['n'] ?? 0;

        return (int) ($hi * 4294967296 + $lo);
    }

    private function uInt64le(string $bytes): int
    {
        $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT);
        $lo = \unpack('Vn', \substr($bytes, 0, 4))['n'] ?? 0;
        $hi = \unpack('Vn', \substr($bytes, 4, 4))['n'] ?? 0;

        return (int) ($hi * 4294967296 + $lo);
    }
}
