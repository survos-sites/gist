<?php
// src/Repository/LemmaRepository.php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Lemma;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LemmaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lemma::class);
    }

    public function upsert(Language $lang, string $head, ?string $pos, ?string $gender, ?array $features): Lemma
    {
        $norm = self::normalize($head);

        /** @var Lemma|null $lemma */
        $lemma = $this->findOneBy(['language' => $lang, 'headword' => $head, 'pos' => $pos]);

        if (!$lemma) {
            $lemma = new Lemma();
            $lemma->language = $lang;
            $lemma->headword = $head;
            $lemma->norm_headword = $norm;
            $lemma->pos = $pos;
            $this->getEntityManager()->persist($lemma);
        }

        $lemma->gender = $gender;
        $lemma->features = $features;

        // No flush here; caller controls batching
        return $lemma;
    }

    public static function normalize(string $s): string
    {
        $s = \mb_strtolower($s);
        // strip diacritics using Unicode normalization
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if ($n !== null) {
            $s = \preg_replace('~\p{Mn}+~u', '', $n) ?? $s;
        }
        return $s;
    }
}
