<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\LemmaRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

#[ORM\Entity(repositoryClass: LemmaRepository::class)]
#[ORM\Table(name: 'lemma')]
#[ORM\Index(columns: ['headword'])]
#[ORM\Index(columns: ['norm_headword'])]
#[ORM\UniqueConstraint(name: 'uniq_lang_head_pos', columns: ['language_id', 'headword', 'pos'])]
#[EntityMeta(icon: 'tabler:letter-case', group: 'Dictionary', label: 'Lemma', description: 'Dictionary headword entry')]
class Lemma
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'Language this lemma belongs to')]
    public Language $language;

    #[ORM\Column(length: 255)]
    #[Field(searchable: true, sortable: true, order: 10)]
    #[ApiProperty(description: 'Original headword as in TEI (orth element)', example: 'laufen')]
    public string $headword;

    #[ORM\Column(length: 255)]
    #[Field(searchable: true, order: 20)]
    #[ApiProperty(description: 'Lowercased/unaccented headword for fast lookups', example: 'laufen')]
    public string $norm_headword;

    #[ORM\Column(length: 16, nullable: true)]
    #[Field(sortable: true, filterable: true, facet: true, order: 30)]
    #[ApiProperty(description: 'Part of speech', example: 'verb')]
    public ?string $pos = null;

    #[ORM\Column(length: 1, nullable: true)]
    #[Field(filterable: true, facet: true, order: 40)]
    #[ApiProperty(description: 'Grammatical gender', example: 'm')]
    public ?string $gender = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(description: 'Extra morphological attributes (case, number, etc.)')]
    public ?array $features = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Field(sortable: true, order: 50)]
    #[ApiProperty(description: 'Frequency rank (lower = more common)', example: 42)]
    public ?int $rank = null;
}
