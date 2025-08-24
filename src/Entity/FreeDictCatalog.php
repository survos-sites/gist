<?php
// src/Entity/FreeDictCatalog.php
declare(strict_types=1);

namespace App\Entity;

use App\Workflow\IFreeDictCatalogWorkflow;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\FreeDictCatalogRepository;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;

#[ORM\Entity(repositoryClass: FreeDictCatalogRepository::class)]
#[ORM\Table(name: 'freedict_catalog')]
#[ORM\UniqueConstraint(name: 'uniq_fdc_name', columns: ['name'])]
class FreeDictCatalog implements MarkingInterface
{
    use MarkingTrait;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 63)]
        private(set) readonly string $name
)
    {
        $this->marking = IFreeDictCatalogWorkflow::PLACE_NEW;
    }

    /** Pair slug like 'afr-deu' from JSON["name"] */

    /** Derived from name: 'afr' */
    #[ORM\Column(length: 16)]
    public string $src;

    /** Derived from name: 'deu' */
    #[ORM\Column(length: 16)]
    public string $dst;

    /** JSON["edition"] e.g. 0.3.3 */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $edition = null;

    /** JSON["headwords"] as int if numeric */
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $headwords = null;

    /** JSON["maintainerName"] */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $maintainerName = null;

    /** JSON["sourceURL"] */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $sourceURL = null;

    /** JSON["status"] (e.g. 'too small') */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $status = null;

    /** Best release URL we intend to use (prefer stardict) */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $bestUrl = null;

    /** Best release platform (stardict|dictd|src|slob) */
    #[ORM\Column(length: 16, nullable: true)]
    public ?string $bestPlatform = null;

    /** Latest release date among releases[] for bestPlatform (ISO string) */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $releaseDate = null;

    /** Latest release version among releases[] for bestPlatform */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $releaseVersion = null;

    /** Latest release size (bytes) from releases[] for bestPlatform */
    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $releaseSize = null;

    /** Whole item from freedict JSON for debugging/completeness */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $raw = null;
}
