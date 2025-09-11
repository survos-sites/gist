<?php
// src/Command/TranslateCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Service\RuleTranslatorService;
use App\Service\StarDictLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:translate', description: 'Translate a string word-by-word (clean output: full line, per-word table, stats)')]
final class TranslateCommand
{
    public function __construct(
        private readonly StarDictLookup $lookup,
        private readonly RuleTranslatorService $rules,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Source language code (en|eng, es|spa, etc.)')]
        string $source,
        #[Argument('Target language code (es|spa, etc.)')]
        string $target,
        #[Argument('The text to translate (quote it)')]
        string $text,
        #[Option('Use simple rules (currently en→es articles/plurals demo)', shortcut: 'r')]
        bool $useRules = false,
        #[Option('Max definition length per word (0 = unlimited)', shortcut: 'D')]
        int $defMax = 120
    ): int {
        try {
            // Tokenize preserving delimiters so we can rebuild the final string
            $parts = \preg_split('~(\p{L}+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

            if ($parts === false) {
                $io->error('Tokenizer failed.');
                return 1;
            }

            $rows = [];
            $wordCount = 0;
            $hitCount  = 0;

            $out = '';

            // Build a clean translation token-by-token
            for ($i = 0; $i < \count($parts); $i++) {
                $chunk = $parts[$i];
                if ($chunk === '') continue;

                if (\preg_match('~^\p{L}+$~u', $chunk) !== 1) {
                    // punctuation/space
                    $out .= $chunk;
                    continue;
                }

                $wordCount++;

                // Raw token translation (may be a full gloss)
                $rawTr  = $this->lookup->translateWordDirect($source, $target, $chunk);
                $bonus  = $this->lookup->lookupOne($source, $target, $chunk) ?? '';

                // Heuristic simplification: pull a headword-ish token from the gloss
                $simple = $this->simplifyGlossToHeadword($rawTr, $target);
                if ($simple !== null && $simple !== '') {
                    $out .= $simple;
                    if ($simple !== $chunk) {
                        $hitCount++;
                    }
                } else {
                    // If simplification failed and rawTr is a single word, use it; else keep source token
                    $out .= (\preg_match('~^\p{L}+$~u', $rawTr) === 1) ? $rawTr : $chunk;
                    if ($rawTr !== $chunk) {
                        $hitCount++;
                    }
                }

                // Clean the definition for the table
                $def = $this->cleanDefinition($bonus);
                if ($defMax > 0 && \mb_strlen($def) > $defMax) {
                    $def = \mb_substr($def, 0, $defMax) . '…';
                }

                $rows[] = [
                    $chunk,
                    '→',
                    $this->lastAddedToken($out), // the clean token we just appended
                    $def,
                ];
            }

            // 1) Full translated string (clean)
            $io->writeln($out);
            $io->newLine();

            // 2) Per-word table
            if ($rows) {
                $io->section('Per-word details');
                $io->table(['Word', '', 'Translation', 'Definition (bonus)'], $rows);
            } else {
                $io->note('No letter tokens detected in input.');
            }

            // 3) Stats
            $io->section('Stats');
            $hitRate = $wordCount > 0 ? \sprintf('%.1f%%', ($hitCount / $wordCount) * 100) : '—';
            $io->listing([
                "Tokens (letters): $wordCount",
                "Matched translations: $hitCount",
                "Hit rate: $hitRate",
            ]);

            $codes = $this->normalizeCodes($source, $target);
            $pairStats = $this->pairStats($codes['src3'], $codes['dst3']);

            if ($pairStats) {
                $io->text(\sprintf(
                    "Loaded: src lemmas %s | dst lemmas %s | edges %s (pair %s→%s)",
                    \number_format($pairStats['src_lemmas']),
                    \number_format($pairStats['dst_lemmas']),
                    \number_format($pairStats['edges']),
                    $codes['src3'],
                    $codes['dst3']
                ));
                if ($pairStats['version'] || $pairStats['date']) {
                    $io->text("Release: " . \trim("v{$pairStats['version']} {$pairStats['date']}"));
                }
            } else {
                $io->text("Loaded: (no dictionary stats found for {$codes['src3']}→{$codes['dst3']})");
            }

            return 0;

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }
    }

    /** Extract the last token we appended to $out (for the table display). */
    private function lastAddedToken(string $out): string
    {
        if ($out === '') return '';
        if (\preg_match('~(\p{L}+)[^\p{L}]*\z~u', $out, $m) === 1) {
            return (string)$m[1];
        }
        return '';
    }

    /** Convert possibly-2-letter codes to ISO-639-3 for pair stats, keep originals too. */
    private function normalizeCodes(string $src, string $dst): array
    {
        $map = [
            'en' => 'eng', 'es' => 'spa', 'fr' => 'fra', 'de' => 'deu', 'it' => 'ita',
            'pt' => 'por', 'nl' => 'nld', 'ar' => 'ara', 'ca' => 'cat', 'af' => 'afr',
        ];
        $src2 = \strtolower(\trim($src));
        $dst2 = \strtolower(\trim($dst));
        return [
            'src2' => $src2,
            'dst2' => $dst2,
            'src3' => \strlen($src2) === 3 ? $src2 : ($map[$src2] ?? $src2),
            'dst3' => \strlen($dst2) === 3 ? $dst2 : ($map[$dst2] ?? $dst2),
        ];
    }

    /**
     * Return DB stats for a pair (lemmas & edges) + dictionary release info.
     * Fixed SQL (CROSS JOIN) so Postgres is happy.
     * @return array{src_lemmas:int,dst_lemmas:int,edges:int,version:string|null,date:string|null}|null
     */
    private function pairStats(string $src3, string $dst3): ?array
    {
        $conn = $this->em->getConnection();

        // lookup language ids
        $lang = $conn->fetchAssociative(<<<SQL
            SELECT ls.id AS src_id, ld.id AS dst_id
            FROM lang ls
            CROSS JOIN lang ld
            WHERE ls.code3 = :src AND ld.code3 = :dst
        SQL, ['src' => $src3, 'dst' => $dst3]);

        if (!$lang) {
            return null;
        }

        $srcId = (int)$lang['src_id'];
        $dstId = (int)$lang['dst_id'];

        $counts = $conn->fetchAssociative(<<<SQL
            SELECT
              (SELECT COUNT(*) FROM lemma WHERE language_id = :src) AS src_lemmas,
              (SELECT COUNT(*) FROM lemma WHERE language_id = :dst) AS dst_lemmas,
              (
                SELECT COUNT(*)
                FROM translation t
                  JOIN lemma sl ON sl.id = t.src_lemma_id
                  JOIN lemma dl ON dl.id = t.dst_lemma_id
                WHERE sl.language_id = :src
                  AND dl.language_id = :dst
              ) AS edges
        SQL, ['src' => $srcId, 'dst' => $dstId]);

        $dict = $conn->fetchAssociative(<<<SQL
            SELECT d.release_version, d.release_date
            FROM dictionary d
            WHERE d.src_id = :src AND d.dst_id = :dst
            ORDER BY d.id ASC
            LIMIT 1
        SQL, ['src' => $srcId, 'dst' => $dstId]);

        return [
            'src_lemmas' => (int)($counts['src_lemmas'] ?? 0),
            'dst_lemmas' => (int)($counts['dst_lemmas'] ?? 0),
            'edges'      => (int)($counts['edges'] ?? 0),
            'version'    => $dict['release_version'] ?? null,
            'date'       => $dict['release_date'] ?? null,
        ];
    }

    /** Strip IPA / slashes / HTML and collapse whitespace; keep a compact, human definition. */
    private function cleanDefinition(string $s): string
    {
        if ($s === '') return '';
        // remove IPA groups like /.../ or [...] at start
        $s = \preg_replace('~^(\s*(/[^\n/]+/|\[[^\]]+\])\s*,?)+~u', '', $s) ?? $s;
        // strip HTML tags and control chars
        $s = \strip_tags($s);
        $s = \preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $s) ?? $s;
        // collapse whitespace
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        return \trim($s);
    }

    /**
     * Try to reduce a StarDict gloss into a single headword-ish token for the target language.
     * Heuristics:
     *  - Drop IPA (/.../, [...])
     *  - Remove obvious English POS words: noun|verb|adj|adv|prep|det|pron|interj
     *  - Split on non-letters, scan for plausible tokens (2..40 chars)
     *  - For Spanish, prefer a small set of common function words if present (en, a, de, con, por, para, el, la, los, las, un, una, unos, unas, y, o)
     *  - Otherwise pick the last plausible token (often where WikDict/StarDict append the translation)
     */
    private function simplifyGlossToHeadword(string $gloss, string $target): ?string
    {
        if ($gloss === '') return null;

        // remove IPA and tags
        $s = \preg_replace('~(/[^/]+/|\[[^\]]+\])~u', ' ', $gloss) ?? $gloss;
        $s = \strip_tags($s);
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        $s = \trim($s);

        // remove common English POS markers to reduce noise
        $s = \preg_replace('~\b(noun|verb|adjective|adj|adverb|adv|preposition|prep|determiner|det|pronoun|pron|interjection|interj)\b~iu', ' ', $s) ?? $s;

        // collect candidate tokens (letters only)
        \preg_match_all('~\p{L}{2,40}~u', $s, $m);
        $cands = $m[0] ?? [];
        if (!$cands) {
            return null;
        }

        $t = \strtolower(\trim($target));

        // tiny Spanish shortlist to improve function words
        if ($t === 'es' || $t === 'spa') {
            $preferred = ['en','a','de','con','por','para','el','la','los','las','un','una','unos','unas','y','o'];
            foreach ($cands as $w) {
                $lw = \mb_strtolower($w);
                if (\in_array($lw, $preferred, true)) {
                    return $lw;
                }
            }
        }

        // Often the last token is the actual translation headword in these dicts
        $last = (string)\end($cands);
        return \mb_strtolower($last);
    }
}
