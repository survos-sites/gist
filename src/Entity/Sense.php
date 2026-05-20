<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\SenseRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

#[ORM\Entity(repositoryClass: SenseRepository::class)]
#[ORM\Table(name: 'sense')]
#[ORM\Index(columns: ['lemma_id'])]
#[EntityMeta(icon: 'tabler:bulb', group: 'Dictionary', label: 'Sense', description: 'A single meaning/gloss for a lemma')]
class Sense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'The lemma this sense belongs to')]
    public Lemma $lemma;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Field(searchable: true, order: 10)]
    #[ApiProperty(description: 'Free-text gloss (sanitized from TEI sense text)', example: 'to run, to walk quickly')]
    public ?string $gloss = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(description: 'Usage examples extracted from TEI')]
    public ?array $examples = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Field(sortable: true, order: 20)]
    #[ApiProperty(description: 'Sense ordering within the lemma entry (1 = primary)', example: 1)]
    public ?int $rank = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(description: 'Raw TEI bits preserved for debugging')]
    public ?array $raw = null;
}
