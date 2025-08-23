<?php
// src/Controller/LibreController.php
declare(strict_types=1);

namespace App\Controller;

use App\Service\StarDictLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Intl\Languages;

/**
 * Minimal LibreTranslate-compatible endpoints:
 *  - GET  /languages
 *  - POST /translate
 */
final class LibreController extends AbstractController
{
    public function __construct(
        private readonly StarDictLookup $lookup,
    ) {}

    #[Route(path: '/languages', name: 'lt_languages', methods: ['GET'])]
    public function languages(): JsonResponse
    {
        $codes = $this->lookup->availableLanguageCodes(); // now StarDict-only
        $out = \array_map(
            fn(string $c) => ['code' => $c, 'name' => $this->nameFor($c)],
            $codes
        );

        return $this->json($out);
    }

    #[Route(path: '/translate', name: 'lt_translate', methods: ['POST'])]
    public function translate(Request $request): JsonResponse
    {
        $payload = $request->toArray() ?: $request->request->all();

        $q      = (string)($payload['q'] ?? '');
        $source = (string)($payload['source'] ?? '');
        $target = (string)($payload['target'] ?? '');

        if ($q === '' || $source === '' || $target === '') {
            return $this->json(['error' => 'Missing q, source, or target.'], 400);
        }

        try {
            $translated = $this->lookup->translateWordByWord($source, $target, $q);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        return $this->json(['translatedText' => $translated]);
    }

    private function nameFor(string $code): string
    {
        // Try direct
        $name = Languages::getName($code) ?? null;
        if ($name) return $name;

        // Try converting 3-letter to 2-letter, then name
        if (\strlen($code) === 3) {
            // Languages::getAlpha2Code was added in Symfony 6.2; if not available, fallback.
            if (\method_exists(Languages::class, 'getAlpha2Code')) {
                $alpha2 = Languages::getAlpha2Code($code) ?? null;
                if ($alpha2) {
                    $name = Languages::getName($alpha2) ?? null;
                    if ($name) return $name;
                }
            }
        }

        // Fallback: uppercase code
        return \strtoupper($code);
    }
}
