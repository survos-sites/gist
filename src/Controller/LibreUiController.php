<?php

// src/Controller/LibreUiController.php
declare(strict_types=1);

namespace App\Controller;

use App\Service\RuleTranslatorService;
use App\Service\StarDictLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LibreUiController extends AbstractController
{
    private const ISO3_TO_2 = [
        'eng' => 'en', 'deu' => 'de', 'ger' => 'de', 'fra' => 'fr', 'fre' => 'fr', 'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'cat' => 'ca', 'afr' => 'af', 'ara' => 'ar', 'bre' => 'br', 'nld' => 'nl', 'dut' => 'nl',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly StarDictLookup $lookup,
        private readonly RuleTranslatorService $rules,
    ) {
    }

    #[Route(path: '/', name: 'libre_ui', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $codes = $this->lookup->availableLanguageCodes();
        $languages = $this->codesToNames($codes);

        $q = (string) $request->query->get('q', '');
        $source = (string) $request->query->get('source', '');
        $target = (string) $request->query->get('target', '');
        $viaHttp = (bool) $request->query->get('via_http', false);
        $useRules = (bool) $request->query->get('use_rules', false);

        $translatedText = null;
        $error = null;

        if ('' !== $q && '' !== $source && '' !== $target) {
            try {
                if ($viaHttp) {
                    $resp = $this->http->request('POST', '/translate', [
                        'json' => ['q' => $q, 'source' => $source, 'target' => $target, 'mode' => $useRules ? 'rules' : 'text'],
                        'timeout' => 20,
                    ]);
                    if (200 !== $resp->getStatusCode()) {
                        $error = 'Translation API returned HTTP '.$resp->getStatusCode();
                    } else {
                        $data = $resp->toArray(false);
                        $translatedText = $data['translatedText'] ?? null;
                        $error = $data['error'] ?? $error;
                    }
                } else {
                    // Build headword-only word-for-word (same heuristic as CLI)
                    $translatedText = $useRules
                        ? $this->rules->translate($source, $target, $q, 'rules') // uses StarDictLookup inside
                        : $this->wordForWordHeadwords($source, $target, $q);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('libre/index.html.twig', [
            'languages' => $languages,
            'prev_q' => $q,
            'prev_source' => $source,
            'prev_target' => $target,
            'translatedText' => $translatedText,
            'error' => $error,
            'prev_via_http' => $viaHttp ? 1 : 0,
            'prev_use_rules' => $useRules ? 1 : 0,
        ]);
    }

    /** Build a copy-friendly headword-only translation. */
    private function wordForWordHeadwords(string $src, string $dst, string $text): string
    {
        $parts = \preg_split('~(\p{L}+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $text;
        }

        $out = '';
        foreach ($parts as $chunk) {
            if ('' === $chunk) {
                continue;
            }
            if (1 !== \preg_match('~^\p{L}+$~u', $chunk)) {
                $out .= $chunk;
                continue;
            }
            $raw = $this->lookup->translateWordDirect($src, $dst, $chunk);
            $head = $this->simplifyGlossToHeadword($raw, $dst, $chunk);
            if ($head && '' !== $head) {
                $out .= $head;
            } else {
                $out .= (1 === \preg_match('~^\p{L}+$~u', $raw)) ? $raw : $chunk;
            }
        }

        return $out;
    }

    /** Same two-pass simplifier as CLI (trimmed for UI). */
    private function simplifyGlossToHeadword(string $gloss, string $target, string $srcToken): ?string
    {
        if ('' === $gloss) {
            return null;
        }

        $t = \strtolower($target);
        $srcLower = \mb_strtolower($srcToken);
        if (('es' === $t || 'spa' === $t) && 'the' === $srcLower) {
            return 'el';
        }

        $s = \preg_replace('~</?[a-z][a-z0-9:-]*[^>]*>~i', ' ', $gloss) ?? $gloss;
        $s = \strtr($s, ['<' => ' ', '>' => ' ']);
        $s = \preg_replace('~(/[^/]+/|\[[^\]]+\])~u', ' ', $s) ?? $s;
        $s = \strip_tags($s);
        $s = \preg_replace('~\b(noun|verb|adjective|adj|adverb|adv|preposition|prep|determiner|det|pronoun|pron|interjection|interj)\b~iu', ' ', $s) ?? $s;
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        $s = \trim($s);

        \preg_match_all('~\p{L}{2,40}~u', $s, $m);
        $cands = $m[0] ?? [];
        if (!$cands) {
            return null;
        }

        $junk = \array_flip([
            'comp', 'comparative', 'superlative', 'plural', 'singular', 'pl', 'sg',
            'masc', 'fem', 'neut', 'm', 'f', 'n',
            'of', 'with', 'without', 'having', 'color', 'structure', 'expression', 'series', 'scale', 'position', 'price', 'time',
            'program', 'demonstration', 'example', 'countable', 'uncountable', 'often',
            'a', 'an', 'the', 'at', 'in', 'on', 'to', 'for', 'from', 'by', 'and', 'or', 'as', 'is', 'are', 'be', 'was', 'were', 'this', 'that', 'which', 'who', 'whom', 'whose', 'been', 'being',
        ]);

        $isEs = ('es' === $t || 'spa' === $t);
        $preferredEs = ['el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'en', 'a', 'de', 'con', 'por', 'para', 'y', 'o'];

        if ($isEs) {
            foreach ($cands as $w) {
                $lw = \mb_strtolower($w);
                if (\in_array($lw, $preferredEs, true)) {
                    return $lw;
                }
            }
        }

        for ($i = \count($cands) - 1; $i >= 0; --$i) {
            $lw = \mb_strtolower($cands[$i]);
            if (!isset($junk[$lw])) {
                return $lw;
            }
        }

        return \mb_strtolower((string) \end($cands));
    }

    /** @param string[] $codes */
    private function codesToNames(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $name = null;
            try {
                $name = Languages::getName($c);
            } catch (MissingResourceException) {
                $alpha2 = self::ISO3_TO_2[\strtolower($c)] ?? null;
                if ($alpha2) {
                    try {
                        $name = Languages::getName($alpha2);
                    } catch (\Throwable) {
                    }
                }
            } catch (\Throwable) {
            }
            $out[$c] = $name ?: \strtoupper($c);
        }
        \asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }
}
