<?php
// src/Entity/Sense.php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\SenseRepository;

#[ORM\Entity(repositoryClass: SenseRepository::class)]
#[ORM\Table(name: 'sense')]
#[ORM\Index(columns: ['lemma_id'])]
class Sense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Lemma::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Lemma $lemma;

    // Free text gloss (sanitized from TEI sense text)
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $gloss = null;

    // Examples/usage extracted from TEI (optional)
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $examples = null;

    // Sense order/rank under the lemma entry
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $rank = null;

    // We keep raw bits if helpful for debugging
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $raw = null;
}
