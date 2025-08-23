<?php
// src/Repository/SenseRepository.php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sense::class);
    }
}
