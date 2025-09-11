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
     * Translate a full string word-by-word, preserving non-letters.
     */
    public function translateWordByWord(string $src, string $dst, string $text): string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        $parts = \preg_split('~(\p{L}+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

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
     * Translate a single token. Falls back to original token if not found.
     */
    public function translateWordDirect(string $src, string $dst, string $token): string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        // exact
        $v = $this->lookup($pair, $token);
        if ($v !== null) return $v;

        // lower-cased
        $lower = \mb_strtolower($token);
        if ($lower !== $token) {
            $v = $this->lookup($pair, $lower);
            if ($v !== null) return $v;
        }

        return $token;
    }

    /**
     * Return a cleaned first definition/gloss (best-effort) for a token.
     * Useful for lightweight heuristics (gender/number).
     */
    public function lookupOne(string $src, string $dst, string $token): ?string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        // try exact then lower
        $v = $this->lookup($pair, $token);
        if ($v !== null) return $v;

        $lower = \mb_strtolower($token);
        if ($lower !== $token) {
            $v = $this->lookup($pair, $lower);
            if ($v !== null) return $v;
        }
        return null;
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

    private function pairFrom(string $src, string $dst): string
    {
        return $this->normalizeCode($src) . '-' . $this->normalizeCode($dst);
    }

    private function normalizeCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        if (\strlen($code) === 3) {
            return $code;
        }
        // Common ISO-639-1 → ISO-639-3 map (extend as needed)
        return match ($code) {
            'en' => 'eng',
            'es' => 'spa',
            'fr' => 'fra',
            'de' => 'deu',
            'it' => 'ita',
            'pt' => 'por',
            'nl' => 'nld',
            'ar' => 'ara',
            'ca' => 'cat',
            'af' => 'afr',
            default => $code, // if unknown 2-letter, try as-is
        };
    }

    private function translateToken(string $pair, string $word): string
    {
        $v = $this->lookup($pair, $word);
        if ($v !== null) return $v;

        $lower = \mb_strtolower($word);
        if ($lower !== $word) {
            $v = $this->lookup($pair, $lower);
            if ($v !== null) return $v;
        }

        return $word;
    }

    private function lookup(string $pair, string $key): ?string
    {
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
            return;
        }

        $row = $this->repo->findOneBy(['name' => $pair]);
        if (!$row || ($row->bestPlatform ?? '') !== 'stardict' || !$row->bestUrl) {
            throw new \RuntimeException("No StarDict release for '$pair' (run: bin/console app:load).");
        }

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

        if ($version === '2.4.2' && $bits === 32) {
            $idx = $this->svc->ensureIdxPath(\dirname($ifo));
            $dictAny = $this->svc->ensureDictPath(\dirname($ifo), false); // allow .dict.dz
            $this->skoro[$pair] = StarDict::createFromFiles($ifo, $idx, $dictAny);
            return;
        }

        $idx = $this->svc->ensureIdxPath(\dirname($ifo));
        $dictPlain = $this->svc->ensureDictPath(\dirname($ifo), true); // force .dict
        $this->dictPath[$pair] = $dictPlain;
        $this->maps[$pair] = $this->buildIndexMap($idx, $bits);
    }

    private function buildIndexMap(string $idxPath, int $bits): array
    {
        $map = [];
        $f = \fopen($idxPath, 'rb');
        if (!$f) {
            throw new \RuntimeException("Cannot open $idxPath");
        }
        try {
            while (!\feof($f)) {
                $head = '';
                while (!\feof($f)) {
                    $ch = \fread($f, 1);
                    if ($ch === '' || $ch === false) { $head = ''; break; }
                    if (\ord($ch) === 0) break;
                    $head .= $ch;
                    if (\strlen($head) > 10000) break;
                }
                if ($head === '') break;

                if ($bits === 64) {
                    $ob = \fread($f, 8);
                    $sb = \fread($f, 4);
                    if ($ob === false || $sb === false) break;
                    $off = $this->uInt64be($ob);
                    $size = $this->uInt32be($sb);
                } else {
                    $ob = \fread($f, 4);
                    $sb = \fread($f, 4);
                    if ($ob === false || $sb === false) break;
                    $off = $this->uInt32be($ob);
                    $size = $this->uInt32be($sb);
                }

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
        return (int)($hi * 4294967296 + $lo);
    }
}
