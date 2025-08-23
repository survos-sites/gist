<?php
// src/Service/FreeDictService.php
declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use StarDict\StarDict;

final class FreeDictService
{
    public const CATALOG_URL = 'https://freedict.org/freedict-database.json';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    public function getCacheDir(): string
    {
        $dir = $this->projectDir . '/var/freedict';
        $this->fs->mkdir($dir);
        return $dir;
    }

    public function fetchCatalog(): array
    {
        $resp = $this->http->request('GET', self::CATALOG_URL, ['timeout' => 60]);
        if (200 !== $resp->getStatusCode()) {
            throw new \RuntimeException('Failed to fetch FreeDict catalog JSON.');
        }
        /** @var array $data */
        $data = $resp->toArray();
        return $data;
    }

    public function pickBestRelease(array $item, bool $stardictOnly = false): array
    {
        $releases = $item['releases'] ?? [];
        if (!\is_array($releases) || $releases === []) {
            throw new \RuntimeException('No releases[] found on item.');
        }
        $pref = $stardictOnly ? ['stardict'] : ['stardict', 'dictd', 'src', 'slob'];
        foreach ($pref as $platform) {
            $filtered = \array_values(\array_filter($releases, fn($r) => ($r['platform'] ?? null) === $platform));
            if ($filtered) {
                \usort($filtered, fn($a, $b) => \strcmp((string)$b['date'], (string)$a['date']));
                $r = $filtered[0];
                return [
                    (string)$r['URL'],
                    (string)$platform,
                    (string)($r['date'] ?? ''),
                    (string)($r['version'] ?? ''),
                    \is_numeric($r['size'] ?? null) ? (int)$r['size'] : null,
                ];
            }
        }
        if ($stardictOnly) return ['', '', '', '', null];
        $r = $releases[0];
        return [(string)$r['URL'], (string)($r['platform'] ?? 'unknown'), (string)($r['date'] ?? ''), (string)($r['version'] ?? ''), \is_numeric($r['size'] ?? null) ? (int)$r['size'] : null];
    }

    public function downloadTo(string $url, string $dest): string
    {
        $this->fs->mkdir(\dirname($dest));
        $response = $this->http->request('GET', $url, ['timeout' => 600]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Failed to download: $url");
        }
        $fp = \fopen($dest, 'wb');
        if (!$fp) throw new \RuntimeException("Cannot open $dest for writing.");
        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) continue;
                $data = $chunk->getContent();
                if ($data !== '') \fwrite($fp, $data);
            }
        } finally { \fclose($fp); }
        return $dest;
    }

    public function ensureStarDictReady(string $pair, string $tarXzUrl): string
    {
        $pairDir = $this->getCacheDir() . "/$pair";
        $this->fs->mkdir($pairDir);
        $archivePath = "$pairDir/stardict.tar.xz";
        if (!\is_file($archivePath) || \filesize($archivePath) === 0) {
            $this->downloadTo($tarXzUrl, $archivePath);
        }
        $extractDir = "$pairDir/stardict";
        $this->fs->mkdir($extractDir);
        if (!$this->dirHasFiles($extractDir)) {
            $cmd = 'tar -xJf ' . \escapeshellarg($archivePath) . ' -C ' . \escapeshellarg($extractDir);
            $this->runShell($cmd, 'Ensure system tar supports -J (xz). On Debian/Ubuntu: apt-get install xz-utils');
        }
        $ifo    = $this->findFirst($extractDir, '/\.ifo$/i');
        $idxAny = $this->findFirst($extractDir, '/\.idx(\.gz)?$/i');
        $dictAny= $this->findFirst($extractDir, '/\.dict(\.dz)?$/i');
        if (!$ifo || !$idxAny || !$dictAny) {
            throw new \RuntimeException("StarDict files not found in $extractDir");
        }
        return \dirname($ifo);
    }

    /** Read FIRST record; 2.4.2 + 32‑bit tries skoro; others use manual. Always read plain .dict in manual path. */
    public function starDictFirstRecord(string $dir): array
    {
        $ifoPath = $this->findFirst($dir, '/\.ifo$/i');
        if (!$ifoPath) throw new \RuntimeException("Missing .ifo in $dir");
        $ifo = $this->parseIfo($ifoPath);
        $version = $ifo['version'] ?? '';
        $idxOffsetBits = (int)($ifo['idxoffsetbits'] ?? 32);
        $bookname = $ifo['bookname'] ?? \basename($dir);

        if ($version === '2.4.2' && $idxOffsetBits === 32) {
            try {
                $idxPath  = $this->ensureIdxPath(\dirname($ifoPath));
                $dictAny  = $this->ensureDictPath(\dirname($ifoPath), false); // .dict.dz allowed by library
                $dict = StarDict::createFromFiles($ifoPath, $idxPath, $dictAny);
                $meta = $dict->getDict();
                $it = $dict->getIndex()->getIterator();
                $firstKey = null; foreach ($it as $k => $v) { $firstKey = (string)$k; break; }
                if ($firstKey === null) return ['found' => false, 'message' => 'Index is empty'];
                $results = $dict->get($firstKey);
                $firstVal = null; foreach ($results as $r) { $firstVal = $r->getValue(); break; }
                return [
                    'found' => true,
                    'bookname' => $meta->getBookname() ?: $bookname,
                    'headword' => $firstKey,
                    'value_snippet' => \mb_substr(\preg_replace('~\s+~u', ' ', \trim(\strip_tags((string)$firstVal))) ?? (string)$firstVal, 0, 400),
                    'reader' => 'skoro',
                ];
            } catch (\Throwable $e) {
                // fall through to manual
            }
        }

        // Manual path (always plain .dict):
        $idxPath  = $this->ensureIdxPath(\dirname($ifoPath));
        $dictPath = $this->ensureDictPath(\dirname($ifoPath), true); // force plain .dict
        $info = \stat($dictPath);
        $dictSize = $info ? (int)$info['size'] : 0;
        $rows = $this->readIndexEntries($idxPath, $idxOffsetBits, 1);
        if ($rows === []) return ['found' => false, 'message' => 'Index empty'];
        $r = $rows[0];

        $pick = $this->chooseEndian($dictSize, $r['off_be'], $r['size_be'], $r['off_le'], $r['size_le']);
        $payload = $this->readSlice($dictPath, $pick['offset'], $pick['size'], 2048);
        $snippet = $this->previewText($payload, 400);

        return [
            'found' => true,
            'bookname' => $bookname,
            'headword' => $r['headword'],
            'value_snippet' => $snippet,
            'reader' => 'manual-' . $pick['endian'],
        ];
    }

    /* ========================= Debug helpers / public utils ========================= */

    public function parseIfo(string $ifoPath): array
    {
        $out = [];
        $lines = \file($ifoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $out;
        foreach ($lines as $line) {
            if (!\str_contains($line, '=')) continue;
            [$k, $v] = \explode('=', $line, 2);
            $out[\trim($k)] = \trim($v);
        }
        return $out;
    }

    public function ensureIdxPath(string $dir): string
    {
        $idx = $this->findFirst($dir, '/\.idx$/i');
        if ($idx) return $idx;
        $idxGz = $this->findFirst($dir, '/\.idx\.gz$/i');
        if ($idxGz) return $this->gunzipToSibling($idxGz);
        throw new \RuntimeException("Missing .idx / .idx.gz in $dir");
    }

    /**
     * Ensure dict path.
     * - If $needPlain=true → return a REAL .dict (gunzip .dict.dz or a gzipped .dict to plain).
     * - If $needPlain=false → return .dict if present else .dict.dz.
     */
    public function ensureDictPath(string $dir, bool $needPlain): string
    {
        $dict = $this->findFirst($dir, '/\.dict$/i');
        if ($dict) {
            if ($needPlain && $this->isGzipFile($dict)) {
                // Rare case: file named .dict but still gzipped → write sibling .dict.unz and use it
                return $this->gunzipToFresh($dict, $dict . '.unz');
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
        if (!$f) throw new \RuntimeException("Cannot open $idxPath");
        try {
            for ($i=0; $i<($limit ?: 1); $i++) {
                $head = '';
                while (!\feof($f)) {
                    $ch = \fread($f, 1);
                    if ($ch === '' || $ch === false) break;
                    if (\ord($ch) === 0) break;
                    $head .= $ch;
                    if (\strlen($head) > 4000) break;
                }
                if ($head === '') break;

                if ($idxOffsetBits === 64) {
                    $ob = \fread($f, 8); $sb = \fread($f, 4); if ($ob === false || $sb === false) break;
                    $off_be = $this->uInt64be($ob); $size_be = $this->uInt32be($sb);
                    $off_le = $this->uInt64le($ob); $size_le = $this->uInt32le($sb);
                } else {
                    $ob = \fread($f, 4); $sb = \fread($f, 4); if ($ob === false || $sb === false) break;
                    $off_be = $this->uInt32be($ob); $size_be = $this->uInt32be($sb);
                    $off_le = $this->uInt32le($ob); $size_le = $this->uInt32le($sb);
                }

                $out[] = [
                    'headword' => $head,
                    'off_be' => $off_be, 'size_be' => $size_be,
                    'off_le' => $off_le, 'size_le' => $size_le,
                    'off_be_hex' => '0x' . \strtoupper(\bin2hex($ob)),
                    'size_be_hex'=> '0x' . \strtoupper(\bin2hex($sb)),
                    'off_le_hex' => '0x' . \strtoupper(\bin2hex(\strrev($ob))),
                    'size_le_hex'=> '0x' . \strtoupper(\bin2hex(\strrev($sb))),
                ];
            }
        } finally { \fclose($f); }
        return $out;
    }

    public function chooseEndian(int $dictSize, int $offBE, int $sizeBE, int $offLE, int $sizeLE): array
    {
        $inBE = $offBE >= 0 && $sizeBE >= 0 && ($offBE + $sizeBE) <= $dictSize;
        $inLE = $offLE >= 0 && $sizeLE >= 0 && ($offLE + $sizeLE) <= $dictSize;
        if ($inBE && !$inLE) return ['endian' => 'BE', 'offset' => $offBE, 'size' => $sizeBE, 'in_bounds' => true];
        if ($inLE && !$inBE) return ['endian' => 'LE', 'offset' => $offLE, 'size' => $sizeLE, 'in_bounds' => true];
        if ($inBE && $inLE)  return ['endian' => 'BE', 'offset' => $offBE, 'size' => $sizeBE, 'in_bounds' => true];
        return ['endian' => 'BE', 'offset' => max(0,$offBE), 'size' => max(0,$sizeBE), 'in_bounds' => false];
    }

    public function readSlice(string $file, int $offset, int $size, int $maxLen = 2048): string
    {
        $f = \fopen($file, 'rb'); if (!$f) return '';
        try {
            \fseek($f, $offset);
            $n = max(0, min($size, $maxLen));
            return $n > 0 ? (string)\fread($f, $n) : '';
        } finally { \fclose($f); }
    }

    public function previewText(string $bytes, int $maxChars = 200): string
    {
        if ($bytes === '') return '(empty)';
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
        for ($i=0; $i<\strlen($hex); $i+=32) $out[] = \substr($hex, $i, 32);
        return \implode("\n", $out);
    }

    private function uInt32be(string $bytes): int { $bytes = (\strlen($bytes)===4)?$bytes:($bytes."\0\0\0\0"); $arr = \unpack('Nn', \substr($bytes,0,4)); return (int)($arr['n'] ?? 0); }
    private function uInt32le(string $bytes): int { $bytes = (\strlen($bytes)===4)?$bytes:($bytes."\0\0\0\0"); $arr = \unpack('Vn', \substr($bytes,0,4)); return (int)($arr['n'] ?? 0); }
    private function uInt64be(string $bytes): int { $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT); $hi = \unpack('Nn', \substr($bytes, 0, 4))['n'] ?? 0; $lo = \unpack('Nn', \substr($bytes, 4, 4))['n'] ?? 0; return (int)($hi * 4294967296 + $lo); }
    private function uInt64le(string $bytes): int { $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT); $lo = \unpack('Vn', \substr($bytes, 0, 4))['n'] ?? 0; $hi = \unpack('Vn', \substr($bytes, 4, 4))['n'] ?? 0; return (int)($hi * 4294967296 + $lo); }

    private function isGzipFile(string $path): bool
    {
        $fh = @\fopen($path, 'rb'); if (!$fh) return false;
        $sig = \fread($fh, 3); \fclose($fh);
        return $sig === "\x1F\x8B\x08";
    }

    /** Gunzip .gz or .dz → sibling without that final extension. */
    private function gunzipToSibling(string $gzOrDzPath): string
    {
        $dest = \preg_replace('~\.(gz|dz)$~i', '', $gzOrDzPath);
        if ($dest === null) throw new \RuntimeException("Bad compressed filename: $gzOrDzPath");
        return $this->gunzipToFresh($gzOrDzPath, $dest);
    }

    private function gunzipToFresh(string $compressedPath, string $dest): string
    {
        if (\is_file($dest) && \filesize($dest) > 0) return $dest;
        $in = \gzopen($compressedPath, 'rb'); if (!$in) throw new \RuntimeException("gzopen failed for $compressedPath");
        $this->fs->mkdir(\dirname($dest));
        $out = \fopen($dest, 'wb'); if (!$out) { \gzclose($in); throw new \RuntimeException("Cannot open $dest for writing"); }
        try {
            while (!\gzeof($in)) {
                $buf = \gzread($in, 8192);
                if ($buf === false) throw new \RuntimeException("gzread error on $compressedPath");
                if ($buf !== '') \fwrite($out, $buf);
            }
        } finally { \gzclose($in); \fclose($out); }
        return $dest;
    }

    private function dirHasFiles(string $dir): bool
    {
        $it = @\scandir($dir); if ($it === false) return false;
        foreach ($it as $name) { if ($name === '.' || $name === '..') continue; return true; }
        return false;
    }

    public function findFirst(string $base, string $regex): ?string
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if ($file->isFile() && \preg_match($regex, $file->getFilename())) {
                return $file->getPathname();
            }
        }
        return null;
    }

    private function runShell(string $cmd, string $errorHint = ''): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($proc)) throw new \RuntimeException("Failed to launch: $cmd");
        $stdout = \stream_get_contents($pipes[1]); \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]); \fclose($pipes[2]);
        $code = \proc_close($proc);
        if ($code !== 0) {
            $msg = "Command failed ($code): $cmd\nSTDERR: $stderr";
            if ($errorHint !== '') $msg .= "\nHINT: $errorHint";
            throw new \RuntimeException($msg);
        }
    }
}
