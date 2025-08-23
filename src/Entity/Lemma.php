<?php
// src/Entity/Lemma.php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\LemmaRepository;

#[ORM\Entity(repositoryClass: LemmaRepository::class)]
#[ORM\Table(name: 'lemma')]
#[ORM\Index(columns: ['headword'])]
#[ORM\Index(columns: ['norm_headword'])]
#[ORM\UniqueConstraint(name: 'uniq_lang_head_pos', columns: ['language_id', 'headword', 'pos'])]
class Lemma
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Language $language;

    // Original headword as in TEI (orth)
    #[ORM\Column(length: 255)]
    public string $headword;

    // Normalized headword (lowercase/unaccented) for fast lookups
    #[ORM\Column(length: 255)]
    public string $norm_headword;

    // Part of speech — keep free-text for now ('noun','verb','adj',...)
    #[ORM\Column(length: 16, nullable: true)]
    public ?string $pos = null;

    // Gender if available — 'm' | 'f' | 'n' | null
    #[ORM\Column(length: 1, nullable: true)]
    public ?string $gender = null;

    // Extra morphological attributes (JSONB in Postgres)
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $features = null;

    // A simple frequency/rank if present (or if we compute one later)
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $rank = null;
}
