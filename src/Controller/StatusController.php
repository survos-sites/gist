<?php
// src/Controller/StatusController.php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;

final class StatusController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/dashboard', name: 'status_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $conn = $this->em->getConnection();

        $dicts = $conn->fetchAllAssociative(<<<SQL
            SELECT
              d.id, d.name, d.src_id AS src_id, d.dst_id AS dst_id,
              ls.code3 AS src_code3, ld.code3 AS dst_code3,
              d.release_version, d.release_date
            FROM dictionary d
            JOIN lang ls ON ls.id = d.src_id
            JOIN lang ld ON ld.id = d.dst_id
            ORDER BY d.name ASC
        SQL);

        $rows = [];
        foreach ($dicts as $d) {
            $srcId = (int)$d['src_id'];
            $dstId = (int)$d['dst_id'];

            $counts = $conn->fetchAssociative(<<<SQL
                SELECT
                  (SELECT COUNT(*) FROM lemma WHERE language_id = :src) AS src_lemmas,
                  (SELECT COUNT(*) FROM lemma WHERE language_id = :dst) AS dst_lemmas,
                  (
                    SELECT COUNT(*)
                    FROM translation t
                    JOIN lemma sl ON sl.id = t.src_lemma_id
                    JOIN lemma dl ON dl.id = t.dst_lemma_id
                    WHERE sl.language_id = :src AND dl.language_id = :dst
                  ) AS edges
            SQL, ['src' => $srcId, 'dst' => $dstId]);

            $longest = $conn->fetchFirstColumn(<<<SQL
                SELECT headword
                FROM lemma
                WHERE language_id = :src
                ORDER BY LENGTH(headword) DESC, headword ASC
                LIMIT 5
            SQL, ['src' => $srcId]);

            $recent = $conn->fetchFirstColumn(<<<SQL
                SELECT headword
                FROM lemma
                WHERE language_id = :src
                ORDER BY id DESC
                LIMIT 5
            SQL, ['src' => $srcId]);

            $rows[] = [
                'id'        => (int)$d['id'],
                'name'      => (string)$d['name'],
                'src'       => (string)$d['src_code3'],
                'dst'       => (string)$d['dst_code3'],
                'version'   => (string)($d['release_version'] ?? ''),
                'date'      => (string)($d['release_date'] ?? ''),
                'counts'    => [
                    'src_lemmas' => (int)($counts['src_lemmas'] ?? 0),
                    'dst_lemmas' => (int)($counts['dst_lemmas'] ?? 0),
                    'edges'      => (int)($counts['edges'] ?? 0),
                ],
                'longest'   => \array_map('strval', $longest),
                'recent'    => \array_map('strval', $recent),
                'translate_url' => sprintf('/?source=%s&target=%s',
                    urlencode((string)$d['src_code3']),
                    urlencode((string)$d['dst_code3'])
                ),
            ];
        }

        return $this->render('status/dashboard.html.twig', ['pairs' => $rows]);
    }
}
