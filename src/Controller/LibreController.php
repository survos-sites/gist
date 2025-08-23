<?php
// src/Controller/LibreController.php
declare(strict_types=1);

namespace App\Controller;

use App\Service\RuleTranslatorService;
use App\Service\StarDictLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LibreController extends AbstractController
{
    public function __construct(
        private readonly StarDictLookup $lookup,
        private readonly RuleTranslatorService $rules,
    ) {}

    #[Route(path: '/languages', name: 'lt_languages', methods: ['GET'])]
    public function languages(): JsonResponse
    {
        $codes = $this->lookup->availableLanguageCodes();
        // Return just codes, like LibreTranslate; let UI name them
        $out = \array_map(fn(string $c) => ['code' => $c, 'name' => $c], $codes);
        return $this->json($out);
    }

    #[Route(path: '/translate', name: 'lt_translate', methods: ['POST'])]
    public function translate(Request $request): JsonResponse
    {
        $payload = $request->toArray() ?: $request->request->all();

        $q      = (string)($payload['q'] ?? '');
        $source = (string)($payload['source'] ?? '');
        $target = (string)($payload['target'] ?? '');
        $mode   = (string)($payload['mode'] ?? 'text'); // 'text' (default) or 'rules'

        if ($q === '' || $source === '' || $target === '') {
            return $this->json(['error' => 'Missing q, source, or target.'], 400);
        }

        try {
            $translated = $mode === 'rules'
                ? $this->rules->translate($source, $target, $q, 'rules')
                : $this->lookup->translateWordByWord($source, $target, $q);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        return $this->json(['translatedText' => $translated]);
    }
}
