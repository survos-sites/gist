<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\TranslationRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\Table(name: 'translation')]
#[ORM\Index(columns: ['src_lemma_id'])]
#[ORM\Index(columns: ['dst_lemma_id'])]
#[ORM\UniqueConstraint(name: 'uniq_src_dst', columns: ['src_lemma_id', 'dst_lemma_id'])]
#[EntityMeta(icon: 'tabler:arrows-exchange', group: 'Dictionary', label: 'Translation', description: 'Directional translation edge between two lemmas')]
class Translation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'Source lemma (the word being translated)')]
    public Lemma $srcLemma;

    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'Target lemma (the translation)')]
    public Lemma $dstLemma;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Field(sortable: true, order: 10)]
    #[ApiProperty(description: 'Translation rank (1 = primary/most common)', example: 1)]
    public ?int $rank = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(description: 'Additional metadata, e.g. relation type (synonym, translation_of)')]
    public ?array $meta = null;
}
