<?php
// src/Service/StarDictLookup.php
declare(strict_types=1);

namespace App\Service;

use App\Repository\FreeDictCatalogRepository;
use StarDict\StarDict;

/**
 * StarDict word lookups with transparent fallback:
 * - Use skoro/stardict for classic 2.4.2 (32-bit) dictionaries.
 * - For v3 dictionaries (idxoffsetbits 32/64), build an in-memory index map and read from .dict.
 *
 * Process-lifetime caches:
 *  - $skoro[Pair] => StarDict
 *  - $maps[Pair]  => array<string word, array{off:int,size:int}>
 *  - $dictPath[Pair] => string (.dict, plain)
 *  - $meta[Pair] => array{bits:int, version:string, bookname:string}
 */
final class StarDictLookup
{
    /** @var array<string, StarDict> */
    private array $skoro = [];
    /** @var array<string, array<string, array{off:int,size:int}>> */
    private array $maps = [];
    /** @var array<string, string> */
    private array $dictPath = [];
    /** @var array<string, array{bits:int, version:string, bookname:string}> */
    private array $meta = [];

    public function __construct(
        private readonly FreeDictService $svc,
        private readonly FreeDictCatalogRepository $repo,
    ) {}

    /**
     * Translate text word-by-word, preserving non-letters.
     */
    public function translateWordByWord(string $src, string $dst, string $text): string
    {
        $pair = "{$src}-{$dst}";
        $this->ensurePairReady($pair);

        $parts = \preg_split('~(\p{L}+)~u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }

        $out = '';
        foreach ($parts as $chunk) {
            if ($chunk === '') continue;
            if (\preg_match('~^\p{L}+$~u', $chunk) === 1) {
                $out .= $this->translateToken($pair, $chunk);
            } else {
                $out .= $chunk;
            }
        }

        return $out;
    }

    /**
     * Languages available (StarDict-only rows).
     * @return string[]
     */
    public function availableLanguageCodes(): array
    {
        $rows = $this->repo->findAll();
        $codes = [];
        foreach ($rows as $row) {
            if (($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
                continue;
            }
            $codes[$row->src] = true;
            $codes[$row->dst] = true;
        }
        return \array_values(\array_keys($codes));
    }

    /* ======================= internal ======================= */

    private function translateToken(string $pair, string $word): string
    {
        // Try exact
        $v = $this->lookup($pair, $word);
        if ($v !== null) return $v;

        // Try lower-cased
        $lower = \mb_strtolower($word);
        if ($lower !== $word) {
            $v = $this->lookup($pair, $lower);
            if ($v !== null) return $v;
        }

        return $word;
    }

    private function lookup(string $pair, string $key): ?string
    {
        // skoro-backed?
        if (isset($this->skoro[$pair])) {
            $dict = $this->skoro[$pair];
            try {
                $results = $dict->get($key);
                foreach ($results as $r) {
                    return $this->cleanVal((string)$r->getValue());
                }
            } catch (\Throwable $e) {
                // ignore and fall through to map if present
            }
        }

        // map-backed?
        if (isset($this->maps[$pair])) {
            $map = $this->maps[$pair];
            if (isset($map[$key])) {
                $off = $map[$key]['off'];
                $size = $map[$key]['size'];
                $payload = $this->svc->readSlice($this->dictPath[$pair], $off, $size, 4096);
                return $this->cleanVal($payload);
            }
        }

        return null;
    }

    private function cleanVal(string $bytes): string
    {
        // Remove control chars, strip tags, collapse whitespace
        $s = \preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $bytes) ?? $bytes;
        if (!\mb_check_encoding($s, 'UTF-8')) {
            $s = \mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        $s = \strip_tags($s);
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        return \trim($s);
    }

    private function ensurePairReady(string $pair): void
    {
        if (isset($this->skoro[$pair]) || isset($this->maps[$pair])) {
            return; // already prepared
        }

        $row = $this->repo->findOneBy(['name' => $pair]);
        if (!$row || ($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
            throw new \RuntimeException("No StarDict release for '$pair' (run: bin/console app:load).");
        }

        // Ensure files on disk
        $dir = $this->svc->ensureStarDictReady($pair, $row->bestUrl);
        $ifo  = $this->svc->findFirst($dir, '/\.ifo$/i');
        if (!$ifo) {
            throw new \RuntimeException("Missing .ifo for '$pair'.");
        }
        $ifoData = $this->svc->parseIfo($ifo);
        $version = $ifoData['version'] ?? '';
        $bits    = (int)($ifoData['idxoffsetbits'] ?? 32);
        $book    = $ifoData['bookname'] ?? $pair;

        $this->meta[$pair] = ['bits' => $bits, 'version' => $version, 'bookname' => $book];

        // Fast path: classic 2.4.2 + 32-bit → use skoro library
        if ($version === '2.4.2' && $bits === 32) {
            $idx = $this->svc->ensureIdxPath(\dirname($ifo));
            $dictAny = $this->svc->ensureDictPath(\dirname($ifo), false); // allow .dict.dz
            $this->skoro[$pair] = StarDict::createFromFiles($ifo, $idx, $dictAny);
            return;
        }

        // Fallback: v3 or other → build in-memory index map and use plain .dict
        $idx = $this->svc->ensureIdxPath(\dirname($ifo));
        $dictPlain = $this->svc->ensureDictPath(\dirname($ifo), true); // force .dict
        $this->dictPath[$pair] = $dictPlain;
        $this->maps[$pair] = $this->buildIndexMap($idx, $bits);
    }

    /**
     * Build an associative map from an .idx file:
     *  word (UTF-8, NUL-terminated) => [off, size]
     *  - Handles 32-bit and 64-bit offsets (big-endian as per spec).
     */
    private function buildIndexMap(string $idxPath, int $bits): array
    {
        $map = [];
        $f = \fopen($idxPath, 'rb');
        if (!$f) {
            throw new \RuntimeException("Cannot open $idxPath");
        }
        try {
            while (!\feof($f)) {
                // Read headword
                $head = '';
                while (!\feof($f)) {
                    $ch = \fread($f, 1);
                    if ($ch === '' || $ch === false) { $head = ''; break; }
                    if (\ord($ch) === 0) break;
                    $head .= $ch;
                    if (\strlen($head) > 10000) break; // guard against corruption
                }
                if ($head === '') {
                    break;
                }

                // Read offset + size (big-endian, spec)
                if ($bits === 64) {
                    $ob = \fread($f, 8);
                    $sb = \fread($f, 4);
                    if ($ob === false || $sb === false) break;
                    $off = $this->uInt64be($ob);
                    $size = $this->uInt32be($sb);
                } else { // 32-bit
                    $ob = \fread($f, 4);
                    $sb = \fread($f, 4);
                    if ($ob === false || $sb === false) break;
                    $off = $this->uInt32be($ob);
                    $size = $this->uInt32be($sb);
                }

                // Store first value (if duplicates exist, keep the first)
                if (!isset($map[$head])) {
                    $map[$head] = ['off' => $off, 'size' => $size];
                }
            }
        } finally {
            \fclose($f);
        }

        return $map;
    }

    private function uInt32be(string $bytes): int
    {
        $bytes = (\strlen($bytes) === 4) ? $bytes : ($bytes . "\0\0\0\0");
        $arr = \unpack('Nn', \substr($bytes, 0, 4));
        return (int)($arr['n'] ?? 0);
    }

    private function uInt64be(string $bytes): int
    {
        $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT);
        $hi = \unpack('Nn', \substr($bytes, 0, 4))['n'] ?? 0;
        $lo = \unpack('Nn', \substr($bytes, 4, 4))['n'] ?? 0;
        // 64-bit safe combine
        return (int)($hi * 4294967296 + $lo);
    }
}
