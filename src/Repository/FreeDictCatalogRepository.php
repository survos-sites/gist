<?php

// src/Repository/FreeDictCatalogRepository.php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\FreeDictCatalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FreeDictCatalog> */
class FreeDictCatalogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FreeDictCatalog::class);
    }

    public function findOneByName(string $name): ?FreeDictCatalog
    {
        return $this->findOneBy(['name' => $name]);
    }

    /** @return FreeDictCatalog[] */
    public function findByMarking(string $marking, ?string $dst = null): array
    {
        $criteria = ['marking' => $marking];
        if ($dst !== null) {
            $criteria['dst'] = $dst;
        }
        return $this->findBy($criteria, ['name' => 'ASC']);
    }
}
