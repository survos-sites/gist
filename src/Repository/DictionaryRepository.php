<?php
// src/Repository/DictionaryRepository.php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dictionary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DictionaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dictionary::class);
    }
}
