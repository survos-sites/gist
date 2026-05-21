<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DbLookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomepageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DbLookupService $lookup,
    ) {}

    #[Route('/', name: 'app_homepage')]
    public function __invoke(): Response
    {
        $conn = $this->em->getConnection();

        $totals = $conn->fetchAssociative(<<<SQL
            SELECT
                (SELECT COUNT(*) FROM lemma)       AS lemmas,
                (SELECT COUNT(*) FROM translation) AS translations,
                (SELECT COUNT(*) FROM lang
                    WHERE id IN (SELECT DISTINCT language_id FROM lemma)) AS languages,
                (SELECT COUNT(*) FROM dictionary)  AS pairs
        SQL);

        $catalog = $conn->fetchAllAssociative(<<<SQL
            SELECT marking, COUNT(*) AS n, SUM(headwords) AS headwords
            FROM freedict_catalog
            GROUP BY marking
            ORDER BY marking
        SQL);

        $catalogByMarking = [];
        foreach ($catalog as $row) {
            $catalogByMarking[$row['marking']] = [
                'count'     => (int) $row['n'],
                'headwords' => (int) $row['headwords'],
            ];
        }

        // Most loaded *→eng pairs for the "explore" section
        $engPairs = $conn->fetchAllAssociative(<<<SQL
            SELECT d.name, ls.code3 AS src, ld.code3 AS dst,
                   COUNT(t.id) AS edges
            FROM dictionary d
            JOIN lang ls ON ls.id = d.src_id
            JOIN lang ld ON ld.id = d.dst_id
            LEFT JOIN translation t ON t.src_lemma_id IN (
                SELECT id FROM lemma WHERE language_id = d.src_id
            )
            WHERE ld.code3 = 'eng'
            GROUP BY d.name, ls.code3, ld.code3
            HAVING COUNT(t.id) > 0
            ORDER BY edges DESC
            LIMIT 10
        SQL);

        return $this->render('homepage/index.html.twig', [
            'totals'          => $totals,
            'catalogByMarking'=> $catalogByMarking,
            'engPairs'        => $engPairs,
            'languages'       => $this->lookup->availableLanguageCodes(),
        ]);
    }
}
