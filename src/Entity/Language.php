<?php
// src/Entity/Language.php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\LanguageRepository;

#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\Table(name: 'lang')]
#[ORM\UniqueConstraint(name: 'uniq_lang_code3', columns: ['code3'])]
#[ORM\UniqueConstraint(name: 'uniq_lang_code2', columns: ['code2'])]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    // ISO-639-3 (e.g. 'eng', 'deu', 'spa')
    #[ORM\Column(length: 3)]
    public string $code3;

    // ISO-639-1 (e.g. 'en', 'de', 'es'), nullable
    #[ORM\Column(length: 2, nullable: true)]
    public ?string $code2 = null;

    #[ORM\Column(length: 80)]
    public string $name = '';

    public function __toString(): string
    {
        return $this->code3;
    }
}
