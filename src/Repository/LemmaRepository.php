<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Lemma;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LemmaRepository extends ServiceEntityRepository
{
    /** @var array<string, Lemma> */
    private array $cache = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lemma::class);
    }

    public function resetCache(): void
    {
        $this->cache = [];
    }

    public function upsert(Language $lang, string $head, ?string $pos, ?string $gender, ?array $features): Lemma
    {
        if (\mb_strlen($head) > 250) {
            $head = \mb_substr($head, 0, 250);
        }
        $norm = self::normalize($head);
        $key  = $lang->code3 . '|' . $head . '|' . ($pos ?? '');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $lemma = $this->findOneBy(['language' => $lang, 'headword' => $head, 'pos' => $pos]);

        if (!$lemma) {
            $lemma               = new Lemma();
            $lemma->language     = $lang;
            $lemma->headword     = $head;
            $lemma->norm_headword = $norm;
            $lemma->pos          = $pos;
            $this->getEntityManager()->persist($lemma);
        }

        $lemma->gender   = $gender;
        $lemma->features = $features;

        return $this->cache[$key] = $lemma;
    }

    public static function normalize(string $s): string
    {
        $s = \mb_strtolower($s);
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if (null !== $n) {
            $s = \preg_replace('~\p{Mn}+~u', '', $n) ?? $s;
        }

        return $s;
    }
}
