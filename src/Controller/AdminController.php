<?php
// src/Controller/AdminController.php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Dictionary;
use App\Entity\Language;
use App\Entity\Lemma;
use App\Entity\Translation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard()
    {
        // Lemma counts per language
        $lemmaRows = $this->em->createQuery(<<<'DQL'
            SELECT l.code3 AS code, COUNT(le.id) AS lemmas
            FROM App\Entity\Lemma le
            JOIN le.language l
            GROUP BY l.code3
            ORDER BY lemmas DESC, code ASC
        DQL)->getArrayResult();

        // Heaviest translation pairs (edges count between languages)
        $pairRows = $this->em->createQuery(<<<'DQL'
            SELECT ls.code3 AS src, ld.code3 AS dst, COUNT(t.id) AS edges
            FROM App\Entity\Translation t
            JOIN t.srcLemma sl
            JOIN sl.language ls
            JOIN t.dstLemma dl
            JOIN dl.language ld
            GROUP BY ls.code3, ld.code3
            ORDER BY edges DESC, src ASC, dst ASC
        DQL)
        ->setMaxResults(50)
        ->getArrayResult();

        // Dictionaries we know about (light, scalar)
        $dictRows = $this->em->createQuery(<<<'DQL'
            SELECT d.id AS id, d.name AS name, s.code3 AS src, dd.code3 AS dst, d.releaseVersion AS release_version
            FROM App\Entity\Dictionary d
            JOIN d.src s
            JOIN d.dst dd
            ORDER BY d.name ASC
        DQL)->getArrayResult();

        return $this->render('admin/dashboard.html.twig', [
            'lemmaRows' => $lemmaRows,
            'pairRows'  => $pairRows,
            'dictRows'  => $dictRows,
        ]);
    }
}
