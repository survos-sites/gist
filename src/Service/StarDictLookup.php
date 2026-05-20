<?php

// src/Service/StarDictLookup.php
declare(strict_types=1);

namespace App\Service;

use App\Repository\FreeDictCatalogRepository;
use StarDict\StarDict;

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
    ) {
    }

    public function translateWordByWord(string $src, string $dst, string $text): string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        $parts = \preg_split('~(\p{L}+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $text;
        }

        $out = '';
        foreach ($parts as $chunk) {
            if ('' === $chunk) {
                continue;
            }
            if (1 === \preg_match('~^\p{L}+$~u', $chunk)) {
                $out .= $this->translateToken($pair, $chunk);
            } else {
                $out .= $chunk;
            }
        }

        return $out;
    }

    public function translateWordDirect(string $src, string $dst, string $token): string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        $v = $this->lookup($pair, $token);
        if (null !== $v) {
            return $v;
        }

        $lower = \mb_strtolower($token);
        if ($lower !== $token) {
            $v = $this->lookup($pair, $lower);
            if (null !== $v) {
                return $v;
            }
        }

        return $token;
    }

    public function lookupOne(string $src, string $dst, string $token): ?string
    {
        $pair = $this->pairFrom($src, $dst);
        $this->ensurePairReady($pair);

        $v = $this->lookup($pair, $token);
        if (null !== $v) {
            return $v;
        }

        $lower = \mb_strtolower($token);
        if ($lower !== $token) {
            $v = $this->lookup($pair, $lower);
            if (null !== $v) {
                return $v;
            }
        }

        return null;
    }

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

    /* ---------- internals ---------- */

    private function pairFrom(string $src, string $dst): string
    {
        return $this->normalizeCode($src).'-'.$this->normalizeCode($dst);
    }

    private function normalizeCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        if (3 === \strlen($code)) {
            return $code;
        }

        return match ($code) {
            'en' => 'eng','es' => 'spa','fr' => 'fra','de' => 'deu','it' => 'ita','pt' => 'por','nl' => 'nld','ar' => 'ara','ca' => 'cat','af' => 'afr',
            default => $code,
        };
    }

    private function translateToken(string $pair, string $word): string
    {
        $v = $this->lookup($pair, $word);
        if (null !== $v) {
            return $v;
        }

        $lower = \mb_strtolower($word);
        if ($lower !== $word) {
            $v = $this->lookup($pair, $lower);
            if (null !== $v) {
                return $v;
            }
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
                    return $this->cleanVal((string) $r->getValue());
                }
            } catch (\Throwable) { /* fall back */
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
        // 1) Drop HTML tags (even if malformed) and any leftover angle brackets
        $s = \preg_replace('~</?[a-z][a-z0-9:-]*[^>]*>~i', ' ', $bytes) ?? $bytes;
        $s = \strtr($s, ['<' => ' ', '>' => ' ']);

        // 2) Strip IPA (/…/ and […]), keep spaces so words don’t glue
        $s = \preg_replace('~(/[^/]+/|\[[^\]]+\])~u', ' ', $s) ?? $s;

        // 3) Remove control chars & HTML (belt-and-suspenders) and collapse whitespace
        $s = \strip_tags($s);
        $s = \preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $s) ?? $s;
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
        $ifo = $this->svc->findFirst($dir, '/\.ifo$/i');
        if (!$ifo) {
            throw new \RuntimeException("Missing .ifo for '$pair'.");
        }

        $ifoData = $this->svc->parseIfo($ifo);
        $version = $ifoData['version'] ?? '';
        $bits = (int) ($ifoData['idxoffsetbits'] ?? 32);

        if ('2.4.2' === $version && 32 === $bits) {
            $idx = $this->svc->ensureIdxPath(\dirname($ifo));
            $dictAny = $this->svc->ensureDictPath(\dirname($ifo), false);
            $this->skoro[$pair] = StarDict::createFromFiles($ifo, $idx, $dictAny);

            return;
        }

        $idx = $this->svc->ensureIdxPath(\dirname($ifo));
        $dictPlain = $this->svc->ensureDictPath(\dirname($ifo), true);
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
                    if ('' === $ch || false === $ch) {
                        $head = '';
                        break;
                    }
                    if (0 === \ord($ch)) {
                        break;
                    }
                    $head .= $ch;
                    if (\strlen($head) > 10000) {
                        break;
                    }
                }
                if ('' === $head) {
                    break;
                }

                if (64 === $bits) {
                    $ob = \fread($f, 8);
                    $sb = \fread($f, 4);
                    if (false === $ob || false === $sb) {
                        break;
                    }
                    $off = $this->uInt64be($ob);
                    $size = $this->uInt32be($sb);
                } else {
                    $ob = \fread($f, 4);
                    $sb = \fread($f, 4);
                    if (false === $ob || false === $sb) {
                        break;
                    }
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
        $bytes = (4 === \strlen($bytes)) ? $bytes : ($bytes."\0\0\0\0");
        $arr = \unpack('Nn', \substr($bytes, 0, 4));

        return (int) ($arr['n'] ?? 0);
    }

    private function uInt64be(string $bytes): int
    {
        $bytes = \str_pad($bytes, 8, "\0", STR_PAD_RIGHT);
        $hi = \unpack('Nn', \substr($bytes, 0, 4))['n'] ?? 0;
        $lo = \unpack('Nn', \substr($bytes, 4, 4))['n'] ?? 0;

        return (int) ($hi * 4294967296 + $lo);
    }
}
