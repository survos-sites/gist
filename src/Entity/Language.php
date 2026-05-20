<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\Table(name: 'lang')]
#[ORM\UniqueConstraint(name: 'uniq_lang_code3', columns: ['code3'])]
#[ORM\UniqueConstraint(name: 'uniq_lang_code2', columns: ['code2'])]
#[EntityMeta(icon: 'tabler:language', group: 'Dictionary', label: 'Language', description: 'ISO language record')]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(length: 3)]
    #[Field(searchable: true, sortable: true, filterable: true, facet: true, order: 10)]
    #[ApiProperty(description: 'ISO 639-3 language code', example: 'eng')]
    public string $code3;

    #[ORM\Column(length: 2, nullable: true)]
    #[Field(sortable: true, order: 20)]
    #[ApiProperty(description: 'ISO 639-1 language code', example: 'en')]
    public ?string $code2 = null;

    #[ORM\Column(length: 80)]
    #[Field(searchable: true, sortable: true, order: 30)]
    #[ApiProperty(description: 'Human-readable language name', example: 'English')]
    public string $name = '';

    public function __toString(): string
    {
        return $this->code3;
    }
}
