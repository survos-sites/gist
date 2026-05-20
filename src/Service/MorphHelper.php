<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

final class MorphHelper
{
    private Inflector $inflector;

    public function __construct()
    {
        $this->inflector = InflectorFactory::create()->build();
    }

    /**
     * Generate reasonable lookup candidates for a token in a given source language.
     * Currently: EN singular/plural + lowercase. Extend here per language.
     *
     * @return list<string> unique candidates, ordered by preference
     */
    public function candidates(string $lang, string $token): array
    {
        $seen = [];
        $add = static function (string $s) use (&$seen): void {
            $k = \mb_strtolower($s);
            if ('' !== $k && !isset($seen[$k])) {
                $seen[$k] = $s;
            }
        };

        $add($token);
        $lower = \mb_strtolower($token);
        if ($lower !== $token) {
            $add($lower);
        }

        $l = \strtolower($lang);
        if ('en' === $l || 'eng' === $l) {
            // Doctrine Inflector returns STRING (not array) for singularize/pluralize.
            $singToken = $this->inflector->singularize($token);
            $singLower = $this->inflector->singularize($lower);
            $plurToken = $this->inflector->pluralize($token);
            $plurLower = $this->inflector->pluralize($lower);

            foreach ((array) $singToken as $sing) {
                $add((string) $sing);
            }
            foreach ((array) $singLower as $sing) {
                $add((string) $sing);
            }

            // Plurals are rarely needed for source lookup, but harmless to include.
            foreach ((array) $plurToken as $pl) {
                $add((string) $pl);
            }
            foreach ((array) $plurLower as $pl) {
                $add((string) $pl);
            }
        }

        return \array_values($seen);
    }
}
