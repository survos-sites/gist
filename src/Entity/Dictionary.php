<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\DictionaryRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

#[ORM\Entity(repositoryClass: DictionaryRepository::class)]
#[ORM\Table(name: 'dictionary')]
#[ORM\UniqueConstraint(name: 'uniq_dictionary_name', columns: ['name'])]
#[EntityMeta(icon: 'tabler:book', group: 'Dictionary', label: 'Dictionary', description: 'Bilingual FreeDict dictionary pair')]
class Dictionary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(length: 63)]
    #[Field(searchable: true, sortable: true, order: 10)]
    #[ApiProperty(description: 'FreeDict pair slug', example: 'eng-spa')]
    public string $name;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'Source language')]
    public Language $src;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[ApiProperty(description: 'Target language')]
    public Language $dst;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 40)]
    #[ApiProperty(description: 'Dictionary edition', example: '0.3.3')]
    public ?string $edition = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 50)]
    #[ApiProperty(description: 'Release version', example: '1.0.0')]
    public ?string $releaseVersion = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 60)]
    #[ApiProperty(description: 'Release date (ISO string)', example: '2023-04-15')]
    public ?string $releaseDate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[ApiProperty(description: 'URL of the downloaded TEI file')]
    public ?string $teiUrl = null;
}
