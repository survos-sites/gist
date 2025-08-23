<?php
// src/Entity/Dictionary.php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DictionaryRepository;

#[ORM\Entity(repositoryClass: DictionaryRepository::class)]
#[ORM\Table(name: 'dictionary')]
#[ORM\UniqueConstraint(name: 'uniq_dictionary_name', columns: ['name'])]
class Dictionary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    // Pair slug from FreeDict (e.g. 'eng-spa', 'cat-ita')
    #[ORM\Column(length: 63)]
    public string $name;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Language $src;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Language $dst;

    // From catalog JSON
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $edition = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $releaseVersion = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $releaseDate = null;

    // Where we downloaded the TEI (for provenance/debug)
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $teiUrl = null;
}
