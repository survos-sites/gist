<?php
// src/Service/DbLookupService.php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Language;
use App\Entity\Lemma;
use App\Repository\LanguageRepository;
use App\Repository\LemmaRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DbLookupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LanguageRepository $langRepo,
        private readonly LemmaRepository $lemmaRepo,
        private readonly TranslationRepository $translationRepo,
    ) {}

    /**
     * Translate text word-by-word using DB lemmas + translation edges.
     * Keeps non-letter chunks intact. Returns primary target lemma for each word (fallback to original).
     */
    public function translateWordByWord(string $srcCode, string $dstCode, string $text, int $maxAlternates = 1): string
    {
        [$src, $dst] = [$this->requireLang($srcCode), $this->requireLang($dstCode)];

        $parts = \preg_split('~(\p{L}+)~u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }

        $out = '';
        foreach ($parts as $chunk) {
            if ($chunk === '') continue;

            if (\preg_match('~^\p{L}+$~u', $chunk) === 1) {
                $out .= $this->translateToken($src, $dst, $chunk, $maxAlternates);
            } else {
                $out .= $chunk; // punctuation/space
            }
        }
        return $out;
    }

    /**
     * Return a readable definition list (joined by '; ') for a single token (best-effort).
     */
    public function lookupOne(string $srcCode, string $dstCode, string $token, int $limit = 5): ?string
    {
        [$src, $dst] = [$this->requireLang($srcCode), $this->requireLang($dstCode)];
        $defs = $this->lookupAlternates($src, $dst, $token, $limit);
        if (!$defs) return null;
        return \implode('; ', $defs);
    }

    /**
     * Languages that currently have lemmas in the DB (return ISO-639-3 codes).
     * @return string[]
     */
    public function availableLanguageCodes(): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchFirstColumn('SELECT DISTINCT l.code3 FROM lemma le JOIN lang l ON l.id = le.language_id ORDER BY 1 ASC');
        return \array_map('strval', $rows);
    }

    /* -------------------------- internals -------------------------- */

    private function translateToken(Language $src, Language $dst, string $word, int $maxAlternates): string
    {
        // exact headword → top translation
        $alts = $this->lookupAlternates($src, $dst, $word, $maxAlternates);
        if ($alts) {
            return $alts[0];
        }

        // lowercase fallback
        $lower = \mb_strtolower($word);
        if ($lower !== $word) {
            $alts = $this->lookupAlternates($src, $dst, $lower, $maxAlternates);
            if ($alts) {
                return $alts[0];
            }
        }

        // (Optional) very light normalization fallback
        $norm = LemmaRepository::normalize($word);
        if ($norm !== $lower) {
            $alts = $this->lookupAlternates($src, $dst, $norm, $maxAlternates);
            if ($alts) {
                return $alts[0];
            }
        }

        return $word;
    }

    /**
     * Return up to $limit target lemma headwords for a src token (ordered by rank then alphabetically).
     * @return string[]
     */
    private function lookupAlternates(Language $src, Language $dst, string $token, int $limit): array
    {
        $norm = LemmaRepository::normalize($token);
        // Find source lemma by exact headword or normalized headword
        $qb = $this->em->createQueryBuilder();
        $qb->select('le')
            ->from(Lemma::class, 'le')
            ->where('le.language = :lang AND (le.headword = :h OR le.norm_headword = :n)')
            ->setMaxResults(5)
            ->setParameters(['lang' => $src->id, 'h' => $token, 'n' => $norm]);
        /** @var Lemma[] $lemmas */
        $lemmas = $qb->getQuery()->getResult();

        if (!$lemmas) {
            return [];
        }

        // fetch translations (ordered)
        $conn = $this->em->getConnection();
        $out = [];
        foreach ($lemmas as $lemma) {
            $rows = $conn->fetchAllAssociative(<<<SQL
                SELECT ld.headword
                FROM translation t
                JOIN lemma ld ON ld.id = t.dst_lemma_id
                WHERE t.src_lemma_id = :srcLemma
                  AND ld.language_id = :dst
                ORDER BY COALESCE(t.rank, 100000), ld.headword
                LIMIT :lim
            SQL, ['srcLemma' => $lemma->id, 'dst' => $dst->id, 'lim' => $limit], ['lim' => \PDO::PARAM_INT]);
            foreach ($rows as $r) {
                $out[] = (string)$r['headword'];
            }
            if ($out) break; // prefer the first matching source lemma
        }

        return \array_values(\array_unique($out));
    }

    private function requireLang(string $code): Language
    {
        $code = \strtolower(\trim($code));
        $lang = \strlen($code) === 2
            ? $this->langRepo->findOneBy(['code2' => $code])
            : $this->langRepo->findOneBy(['code3' => $code]);

        if (!$lang) {
            // Try opposite code length if not found
            $lang = \strlen($code) === 3
                ? $this->langRepo->findOneBy(['code2' => \substr($code, 0, 2)])
                : $this->langRepo->findOneBy(['code3' => $code]); // already tried 2; last hope is 3
        }
        if (!$lang) {
            throw new \RuntimeException("Unknown language code: $code");
        }
        return $lang;
    }
}
