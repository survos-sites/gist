<?php
// src/Command/TranslateCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Service\MorphHelper;
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
        private readonly MorphHelper $morph, // << inject helper
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
            $parts = \preg_split('~(\p{L}+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                $io->error('Tokenizer failed.');
                return 1;
            }

            $rows = [];
            $wordCount = 0;
            $hitCount  = 0;
            $out = '';

            for ($i = 0; $i < \count($parts); $i++) {
                $chunk = $parts[$i];
                if ($chunk === '') continue;

                if (\preg_match('~^\p{L}+$~u', $chunk) !== 1) {
                    $out .= $chunk; // punctuation / whitespace
                    continue;
                }

                $wordCount++;

                // -------- candidate loop (morphology-aware StarDict lookups) --------
                $cands = $this->morph->candidates($source, $chunk);
                // ensure original token first even if helper changed order
                if ($cands[0] ?? '' !== $chunk) { \array_unshift($cands, $chunk); }

                $chosenHead = null;
                $chosenCand = null;
                $chosenRaw  = null;

                foreach ($cands as $cand) {
// Raw gloss (already cleaned by StarDictLookup::cleanVal())
                    $raw = $this->lookup->translateWordDirect($source, $target, $cand);
// Try to reduce to headword-ish token
                    $head = $this->simplifyGlossToHeadword($raw, $target, $cand);

                    if ($io->isVeryVerbose()) {
                        $io->writeln(sprintf(
                            '  · [%s] try cand="%s" → raw="%s" → head="%s"',
                            $chunk,
                            $cand,
                            $this->clip($raw, 80),
                            $head ?? ''
                        ));
                    }

                    /**
                     * Skip “non-result” candidates:
                     * - If StarDict gave us back the candidate unchanged (raw == cand)
                     *   AND our headword simplifier also returns the candidate itself,
                     *   that’s not a translation — keep trying the next candidate.
                     */
                    $rawSame  = \mb_strtolower($raw)  === \mb_strtolower($cand);
                    $headSame = $head !== null && \mb_strtolower($head) === \mb_strtolower($cand);

                    if ($head !== null && $head !== '' && !($rawSame && $headSame)) {
                        $chosenHead = $head;
                        $chosenCand = $cand;
                        $chosenRaw  = $raw;
                        break;
                    }
                }

                // Fallbacks
                if ($chosenHead === null || $chosenHead === '') {
                    $raw = $this->lookup->translateWordDirect($source, $target, $chunk);
                    $head = (\preg_match('~^\p{L}+$~u', $raw) === 1) ? $raw : $chunk;
                    $chosenHead = $head;
                    $chosenCand = $chunk;
                    $chosenRaw  = $raw;
                }

                if ($io->isVeryVerbose()) {
                    $io->writeln(sprintf(
                        '  ✓ [%s] PICK cand="%s" → head="%s"',
                        $chunk, $chosenCand, $chosenHead
                    ));
                }

                $out .= $chosenHead;
                if ($chosenHead !== $chunk) { $hitCount++; }

                // Bonus definition for the table (first successful candidate’s gloss)
                $bonus = $chosenRaw ?? $this->lookup->lookupOne($source, $target, $chunk) ?? '';
                $def = $this->cleanDefinition($bonus);
                if ($defMax > 0 && \mb_strlen($def) > $defMax) {
                    $def = \mb_substr($def, 0, $defMax) . '…';
                }

                $rows[] = [$chunk, '→', $chosenHead, $def];
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

    private function normalizeCodes(string $src, string $dst): array
    {
        $map = ['en'=>'eng','es'=>'spa','fr'=>'fra','de'=>'deu','it'=>'ita','pt'=>'por','nl'=>'nld','ar'=>'ara','ca'=>'cat','af'=>'afr'];
        $src2 = \strtolower(\trim($src));
        $dst2 = \strtolower(\trim($dst));
        return [
            'src2'=>$src2, 'dst2'=>$dst2,
            'src3'=>\strlen($src2)===3 ? $src2 : ($map[$src2]??$src2),
            'dst3'=>\strlen($dst2)===3 ? $dst2 : ($map[$dst2]??$dst2),
        ];
    }

    private function pairStats(string $src3, string $dst3): ?array
    {
        $conn = $this->em->getConnection();

        $lang = $conn->fetchAssociative(<<<SQL
            SELECT ls.id AS src_id, ld.id AS dst_id
            FROM lang ls
            CROSS JOIN lang ld
            WHERE ls.code3 = :src AND ld.code3 = :dst
        SQL, ['src'=>$src3,'dst'=>$dst3]);
        if (!$lang) return null;

        $srcId = (int)$lang['src_id']; $dstId = (int)$lang['dst_id'];

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
        SQL, ['src'=>$srcId,'dst'=>$dstId]);

        $dict = $conn->fetchAssociative(<<<SQL
            SELECT d.release_version, d.release_date
            FROM dictionary d
            WHERE d.src_id = :src AND d.dst_id = :dst
            ORDER BY d.id ASC
            LIMIT 1
        SQL, ['src'=>$srcId,'dst'=>$dstId]);

        return [
            'src_lemmas'=>(int)($counts['src_lemmas']??0),
            'dst_lemmas'=>(int)($counts['dst_lemmas']??0),
            'edges'=>(int)($counts['edges']??0),
            'version'=>$dict['release_version']??null,
            'date'=>$dict['release_date']??null,
        ];
    }

    /** Strip IPA / HTML and collapse whitespace for readable “bonus” defs. */
    private function cleanDefinition(string $s): string
    {
        if ($s === '') return '';
        $s = \preg_replace('~</?[a-z][a-z0-9:-]*[^>]*>~i', ' ', $s) ?? $s;
        $s = \strtr($s, ['<' => ' ', '>' => ' ']);
        $s = \preg_replace('~(/[^/]+/|\[[^\]]+\])~u', ' ', $s) ?? $s;
        $s = \strip_tags($s);
        $s = \preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', '', $s) ?? $s;
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        return \trim($s);
    }

    /**
     * Two-pass headword simplifier:
     *  1) If any preferred Spanish function word appears anywhere, pick it
     *  2) Else pick the last non-junk token
     */
    private function simplifyGlossToHeadword(string $gloss, string $target, string $srcToken): ?string
    {
        if ($gloss === '') return null;

        $t = \strtolower($target);
        $srcLower = \mb_strtolower($srcToken);

        // Special-case EN "the" → default Spanish article "el"
        if (($t === 'es' || $t === 'spa') && $srcLower === 'the') {
            return 'el';
        }

        // Clean: strip tags (even broken), IPA, POS markers; collapse whitespace
        $s = \preg_replace('~</?[a-z][a-z0-9:-]*[^>]*>~i', ' ', $gloss) ?? $gloss;
        $s = \strtr($s, ['<' => ' ', '>' => ' ']);
        $s = \preg_replace('~(/[^/]+/|\[[^\]]+\])~u', ' ', $s) ?? $s;
        $s = \strip_tags($s);
        $s = \preg_replace('~\b(noun|verb|adjective|adj|adverb|adv|preposition|prep|determiner|det|pronoun|pron|interjection|interj)\b~iu', ' ', $s) ?? $s;
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        $s = \trim($s);

        // Candidate tokens (letters only)
        \preg_match_all('~\p{L}{2,40}~u', $s, $m);
        $cands = $m[0] ?? [];
        if (!$cands) return null;

        // Preferred Spanish function words
        $isEs = ($t === 'es' || $t === 'spa');
        $preferredEs = ['el','la','los','las','un','una','unos','unas','en','a','de','con','por','para','y','o'];

        if ($isEs) {
            foreach ($cands as $w) {
                $lw = \mb_strtolower($w);
                if (\in_array($lw, $preferredEs, true)) {
                    return $lw;
                }
            }
        }

        // Junk words to skip when selecting from the end
        static $junk = null;
        if ($junk === null) {
            $junk = \array_flip([
                'comp','comparative','superlative','plural','singular','pl','sg',
                'masc','fem','neut','m','f','n',
                'of','with','without','having','color','structure','expression','series','scale','position','price','time',
                'program','demonstration','example','countable','uncountable','often',
                'a','an','the','at','in','on','to','for','from','by','and','or','as','is','are','be','was','were','this','that','which','who','whom','whose','been','being',
            ]);
        }

        // Choose the last non-junk token
        for ($i = \count($cands) - 1; $i >= 0; $i--) {
            $lw = \mb_strtolower($cands[$i]);
            if (!isset($junk[$lw])) {
                return $lw;
            }
        }

        // Fallback: last token
        return \mb_strtolower((string)\end($cands));
    }

    /** Clip long debug strings. */
    private function clip(string $s, int $max): string
    {
        $s = \preg_replace('~\s+~u', ' ', $s) ?? $s;
        $s = \trim($s);
        return (\mb_strlen($s) > $max) ? (\mb_substr($s, 0, $max) . '…') : $s;
    }
}
