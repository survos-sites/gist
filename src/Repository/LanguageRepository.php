<?php
// src/Repository/LanguageRepository.php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    public function getOrCreate(string $code3, ?string $code2 = null, ?string $name = null): Language
    {
        $lang = $this->findOneBy(['code3' => $code3]);
        if ($lang) {
            // Optionally update code2/name if newly provided
            if ($code2 && $lang->code2 !== $code2) {
                $lang->code2 = $code2;
                $this->getEntityManager()->persist($lang);
            }
            if ($name && $lang->name !== $name) {
                $lang->name = $name;
                $this->getEntityManager()->persist($lang);
            }
            return $lang;
        }

        $lang = new Language();
        $lang->code3 = $code3;
        $lang->code2 = $code2;
        $lang->name = $name ?? $code3;

        $this->getEntityManager()->persist($lang);
        return $lang;
    }
}
