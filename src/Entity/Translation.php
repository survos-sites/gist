<?php
// src/Entity/Translation.php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TranslationRepository;

#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\Table(name: 'translation')]
#[ORM\Index(columns: ['src_lemma_id'])]
#[ORM\Index(columns: ['dst_lemma_id'])]
#[ORM\UniqueConstraint(name: 'uniq_src_dst', columns: ['src_lemma_id', 'dst_lemma_id'])]
class Translation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    // Directional edge (we’ll create the reverse too during import for convenience)
    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Lemma $srcLemma;

    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Lemma $dstLemma;

    // For ranking translations (1 = primary)
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $rank = null;

    // Additional metadata (e.g., relation type: synonym, translation_of, etc.)
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $meta = null;
}
