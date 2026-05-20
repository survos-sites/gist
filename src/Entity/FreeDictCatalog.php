<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Repository\FreeDictCatalogRepository;
use App\Workflow\IFreeDictCatalogWorkflow;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;

#[ORM\Entity(repositoryClass: FreeDictCatalogRepository::class)]
#[ORM\Table(name: 'freedict_catalog')]
#[ORM\UniqueConstraint(name: 'uniq_fdc_name', columns: ['name'])]
#[EntityMeta(icon: 'tabler:database-import', group: 'Dictionary', label: 'FreeDict Catalog', description: 'Raw catalog entry from the FreeDict project JSON feed')]
class FreeDictCatalog implements MarkingInterface
{
    use MarkingTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 63)]
        #[Field(searchable: true, sortable: true, order: 10)]
        #[ApiProperty(description: 'FreeDict pair slug (the primary key)', example: 'afr-deu')]
        private(set) readonly string $name,
    ) {
        $this->marking = IFreeDictCatalogWorkflow::PLACE_NEW;
    }

    #[ORM\Column(length: 16)]
    #[Field(sortable: true, filterable: true, facet: true, order: 20)]
    #[ApiProperty(description: 'Source language ISO 639-3 code, derived from name', example: 'afr')]
    public string $src;

    #[ORM\Column(length: 16)]
    #[Field(sortable: true, filterable: true, facet: true, order: 30)]
    #[ApiProperty(description: 'Target language ISO 639-3 code, derived from name', example: 'deu')]
    public string $dst;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 40)]
    #[ApiProperty(description: 'Dictionary edition from FreeDict JSON', example: '0.3.3')]
    public ?string $edition = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Field(sortable: true, order: 50)]
    #[ApiProperty(description: 'Number of headwords', example: 2000)]
    public ?int $headwords = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Field(searchable: true, sortable: true, order: 60)]
    #[ApiProperty(description: 'Maintainer name from FreeDict JSON', example: 'Michael Bunk')]
    public ?string $maintainerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[ApiProperty(description: 'Source repository URL')]
    public ?string $sourceURL = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Field(sortable: true, filterable: true, facet: true, order: 70)]
    #[ApiProperty(description: 'Dictionary status from FreeDict JSON', example: 'too small')]
    public ?string $status = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[ApiProperty(description: 'Best release download URL (prefer stardict)')]
    public ?string $bestUrl = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Field(sortable: true, filterable: true, facet: true, order: 80)]
    #[ApiProperty(description: 'Best release platform', example: 'stardict')]
    public ?string $bestPlatform = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 90)]
    #[ApiProperty(description: 'Latest release date for bestPlatform (ISO string)', example: '2023-04-15')]
    public ?string $releaseDate = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Field(sortable: true, order: 100)]
    #[ApiProperty(description: 'Latest release version for bestPlatform', example: '1.0.0')]
    public ?string $releaseVersion = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    #[Field(sortable: true, order: 110)]
    #[ApiProperty(description: 'Latest release size in bytes for bestPlatform', example: 512000)]
    public ?int $releaseSize = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[ApiProperty(description: 'Full raw entry from FreeDict JSON for debugging')]
    public ?array $raw = null;
}
