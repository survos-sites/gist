<?php
// src/Service/RuleTranslatorService.php
declare(strict_types=1);

namespace App\Service;

/**
 * A tiny rule-based layer on top of StarDictLookup.
 * Current scope: EN→ES articles & plurals demo; keeps everything else word-by-word.
 *
 * Rules (very intentionally simplistic):
 *  - If source token is 'a'/'an' and next token resolves to a Spanish noun with gender/number,
 *      choose 'un/una/unos/unas' accordingly.
 *  - If source token is 'the', choose 'el/la/los/las' accordingly.
 *  - If dict value appears plural (ends with 's' and not 'es' cases), emit plural article.
 *  - Otherwise fall back to StarDictLookup->translateWordByWord() behavior for tokens.
 *
 * Gender detection heuristics:
 *  - Look for ' m ' or ' f ' or tags like '(m.)', '(f.)', 'noun m', 'nf' in the definition snippet.
 */
final class RuleTranslatorService
{
    public function __construct(
        private readonly StarDictLookup $lookup
    ) {}

    public function translate(string $src, string $dst, string $text, string $mode = 'rules'): string
    {
        if ($mode !== 'rules') {
            return $this->lookup->translateWordByWord($src, $dst, $text);
        }

        // tokenize preserving delimiters
        $parts = \preg_split('~(\p{L}+)~u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $this->lookup->translateWordByWord($src, $dst, $text);
        }

        // Basic English→Spanish article handling only (extend as needed)
        $isEnToEs = ($src === 'eng' || $src === 'en') && ($dst === 'spa' || $dst === 'es');

        $out = '';
        for ($i = 0; $i < \count($parts); $i++) {
            $chunk = $parts[$i];
            if ($chunk === '') continue;

            if (\preg_match('~^\p{L}+$~u', $chunk) !== 1) {
                $out .= $chunk;
                continue;
            }

            $lower = \mb_strtolower($chunk);

            if ($isEnToEs && ($lower === 'a' || $lower === 'an' || $lower === 'the')) {
                // Peek next word (skip delimiters)
                $j = $i + 1;
                while ($j < \count($parts) && \preg_match('~^\p{L}+$~u', (string)($parts[$j] ?? '')) !== 1) {
                    $j++;
                }

                if ($j < \count($parts)) {
                    $noun = (string)$parts[$j];
                    // Lookup Spanish value & guess gender/number
                    $value = $this->lookup->lookupOne($src, $dst, $noun) ?? '';
                    [$gender, $plural] = $this->guessGenderNumberEs($value);

                    if ($lower === 'a' || $lower === 'an') {
                        $article = $plural ? ($gender === 'f' ? 'unas' : 'unos') : ($gender === 'f' ? 'una' : 'un');
                        $out .= $article;
                        continue;
                    }

                    if ($lower === 'the') {
                        $article = $plural ? ($gender === 'f' ? 'las' : 'los') : ($gender === 'f' ? 'la' : 'el');
                        $out .= $article;
                        continue;
                    }
                }
            }

            // Default: use normal token translation
            $out .= $this->lookup->translateWordDirect($src, $dst, $chunk);
        }

        return $out;
    }

    /**
     * Parse a Spanish dict snippet to extract (very roughly) gender & plural.
     * Returns [gender: 'm'|'f'|null, plural: bool]
     */
    private function guessGenderNumberEs(string $snippet): array
    {
        $s = ' ' . \mb_strtolower($snippet) . ' ';

        $gender = null;
        if (\preg_match('~\b(noun|sust|sustantivo)\b.*\bm\b~', $s) || \preg_match('~\bm\W~', $s)) {
            $gender = 'm';
        }
        if (\preg_match('~\b(noun|sust|sustantivo)\b.*\bf\b~', $s) || \preg_match('~\bf\W~', $s)) {
            $gender = 'f';
        }
        // WikDict short tags often carry 'nm' / 'nf'
        if (\preg_match('~\bnm\b~', $s)) $gender = 'm';
        if (\preg_match('~\bnf\b~', $s)) $gender = 'f';

        // crude plural hint: presence of "pl" or headwords ending in 's' is not reliable; rely on the snippet
        $plural = false;
        if (\preg_match('~\b(pl|plural)\b~', $s)) {
            $plural = true;
        }

        return [$gender, $plural];
    }
}
