<?php
// src/Command/TranslateCommand.php
declare(strict_types=1);

namespace App\Command;

use App\Service\RuleTranslatorService;
use App\Service\StarDictLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:translate', description: 'Translate a string word-by-word using FreeDict (optionally with simple rules)')]
final class TranslateCommand
{
    public function __construct(
        private readonly StarDictLookup $lookup,
        private readonly RuleTranslatorService $rules,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Source language code (eng or en, etc.)')]
        string $source,
        #[Argument('Target language code (spa or es, etc.)')]
        string $target,
        #[Argument('The text to translate (quotes recommended)')]
        string $text,
        #[Option('Use simple rule-based layer (articles etc., en→es only for now)', shortcut: 'r')]
        bool $rules = false
    ): int {
        try {
            $out = $rules
                ? $this->rules->translate($source, $target, $text, 'rules')
                : $this->lookup->translateWordByWord($source, $target, $text);

            $io->writeln($out);
            return 0;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 1;
        }
    }
}
