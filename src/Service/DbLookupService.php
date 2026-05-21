<?php

// src/Service/DbLookupService.php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Language;
use App\Entity\Lemma;
use App\Entity\Sense;
use App\Repository\LanguageRepository;
use App\Repository\LemmaRepository;
use App\Repository\SenseRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\DataContracts\Metadata\ContentType;

final class DbLookupService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LanguageRepository $langRepo,
        private readonly LemmaRepository $lemmaRepo,
        private readonly SenseRepository $senseRepo,
        private readonly TranslationRepository $translationRepo,
        private readonly MorphHelper $morph,
    ) {
    }

    /**
     * Translate text word-by-word using DB lemmas + translation edges.
     * Keeps non-letter chunks intact. Returns primary target lemma for each word (fallback to original).
     */
    public function translateWordByWord(string $srcCode, string $dstCode, string $text, int $maxAlternates = 1): string
    {
        [$src, $dst] = [$this->requireLang($srcCode), $this->requireLang($dstCode)];

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

        foreach ($this->morph->candidates($src->code3, $token) as $cand) {
            $defs = $this->lookupAlternates($src, $dst, $cand, $limit);
            if ($defs) {
                return \implode('; ', $defs);
            }
        }

        return null;
    }

    /**
     * Languages that currently have lemmas in the DB (return ISO-639-3 codes).
     *
     * @return string[]
     */
    public function availableLanguageCodes(): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchFirstColumn('SELECT DISTINCT l.code3 FROM lemma le JOIN lang l ON l.id = le.language_id ORDER BY 1 ASC');

        return \array_map('strval', $rows);
    }

    /**
     * Full resolution for a single word: lemma(s), senses, English translations, ContentType.
     * Returns array of match groups (one per POS variant found).
     */
    public function resolve(string $srcCode, string $word): array
    {
        $src = $this->requireLang($srcCode);
        $eng = $this->langRepo->findOneBy(['code3' => 'eng']);
        $norm = LemmaRepository::normalize($word);

        $lemmas = $this->em->createQueryBuilder()
            ->select('le')
            ->from(Lemma::class, 'le')
            ->where('le.language = :lang AND (le.headword = :h OR le.norm_headword = :n)')
            ->setParameter('lang', $src->id)
            ->setParameter('h', $word)
            ->setParameter('n', $norm)
            ->getQuery()->getResult();

        if (!$lemmas) {
            return [];
        }

        $conn = $this->em->getConnection();
        $results = [];

        foreach ($lemmas as $lemma) {
            \assert($lemma instanceof Lemma);

            $senses = $this->senseRepo->findBy(['lemma' => $lemma], ['rank' => 'ASC']);
            $glosses = array_map(fn(Sense $s) => $s->gloss, array_filter($senses, fn(Sense $s) => $s->gloss !== null));

            $translations = [];
            if ($eng) {
                $rows = $conn->fetchAllAssociative(<<<SQL
                    SELECT ld.headword, t.rank
                    FROM translation t
                    JOIN lemma ld ON ld.id = t.dst_lemma_id
                    WHERE t.src_lemma_id = :src AND ld.language_id = :dst
                    ORDER BY COALESCE(t.rank, 100000), ld.headword
                    LIMIT 10
                SQL, ['src' => $lemma->id, 'dst' => $eng->id]);
                $translations = array_column($rows, 'headword');
            }

            // ContentType resolution: first English translation that matches
            $contentType = null;
            foreach ($translations as $term) {
                $ct = ContentType::lookupGenreType($term, ContentType::GENRE_SPECIFIC_MAP, ContentType::GENRE_BASIC_MAP);
                if ($ct) {
                    $contentType = $ct;
                    break;
                }
            }

            $results[] = [
                'lemma'        => $lemma->headword,
                'norm'         => $lemma->norm_headword,
                'pos'          => $lemma->pos,
                'gender'       => $lemma->gender,
                'senses'       => $glosses,
                'translations' => $translations,
                'contentType'  => $contentType,
            ];
        }

        return $results;
    }

    /* -------------------------- internals -------------------------- */

    private function translateToken(Language $src, Language $dst, string $word, int $maxAlternates): string
    {
        // Try morphological candidates in order
        foreach ($this->morph->candidates($src->code3, $word) as $cand) {
            $alts = $this->lookupAlternates($src, $dst, $cand, $maxAlternates);
            if ($alts) {
                return $alts[0];
            }
        }

        // Lowercase fallback (kept for symmetry; MorphHelper already includes lower)
        $lower = \mb_strtolower($word);
        if ($lower !== $word) {
            $alts = $this->lookupAlternates($src, $dst, $lower, $maxAlternates);
            if ($alts) {
                return $alts[0];
            }
        }

        // Light normalization fallback
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
     *
     * @return list<string>
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
            ->setParameter('lang', $src->id)
            ->setParameter('h', $token)
            ->setParameter('n', $norm);

        /** @var list<Lemma> $lemmas */
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
                $out[] = (string) $r['headword'];
            }
            if ($out) {
                break;
            } // prefer the first matching source lemma
        }

        return \array_values(\array_unique($out));
    }

    private function requireLang(string $code): Language
    {
        $code = \strtolower(\trim($code));

        $lang = 2 === \strlen($code)
            ? $this->langRepo->findOneBy(['code2' => $code])
            : $this->langRepo->findOneBy(['code3' => $code]);

        if (!$lang) {
            // Try the opposite code length as a last resort
            $lang = 3 === \strlen($code)
                ? $this->langRepo->findOneBy(['code2' => \substr($code, 0, 2)])
                : $this->langRepo->findOneBy(['code3' => $code]);
        }

        if (!$lang) {
            throw new \RuntimeException("Unknown language code: $code");
        }

        return $lang;
    }
}
