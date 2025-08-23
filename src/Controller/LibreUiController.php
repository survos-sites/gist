<?php
// src/Controller/LibreUiController.php
declare(strict_types=1);

namespace App\Controller;

use App\Service\RuleTranslatorService;
use App\Service\StarDictLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;

final class LibreUiController extends AbstractController
{
    /** Map a few common ISO‑639‑3 → 2 codes to placate symfony/intl (add as needed). */
    private const ISO3_TO_2 = [
        'eng' => 'en', 'deu' => 'de', 'ger' => 'de', 'fra' => 'fr', 'fre' => 'fr',
        'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'cat' => 'ca', 'afr' => 'af',
        'ara' => 'ar', 'bre' => 'br', 'nld' => 'nl', 'dut' => 'nl',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly StarDictLookup $lookup,
        private readonly RuleTranslatorService $rules,
    ) {}

    #[Route(path: '/', name: 'libre_ui', methods: ['GET'])]
    public function index(): Response
    {
        $codes = $this->lookup->availableLanguageCodes();
        $languages = $this->codesToNames($codes);

        return $this->render('libre/index.html.twig', [
            'languages' => $languages,
        ]);
    }

    #[Route(path: '/ui/translate', name: 'libre_ui_translate', methods: ['POST'])]
    public function translate(Request $request): Response
    {
        $q        = (string)$request->request->get('q', '');
        $source   = (string)$request->request->get('source', '');
        $target   = (string)$request->request->get('target', '');
        $viaHttp  = (bool)$request->request->get('via_http', false);
        $useRules = (bool)$request->request->get('use_rules', false);

        $error = null;
        $translatedText = null;

        if ($q === '' || $source === '' || $target === '') {
            $error = 'Please provide text, source, and target.';
        } else {
            try {
                if ($viaHttp) {
                    $resp = $this->http->request('POST', '/translate', [
                        'json' => [
                            'q' => $q, 'source' => $source, 'target' => $target,
                            'mode' => $useRules ? 'rules' : 'text',
                        ],
                        'timeout' => 20,
                    ]);
                    if ($resp->getStatusCode() !== 200) {
                        $error = 'Translation API returned HTTP ' . $resp->getStatusCode();
                    } else {
                        /** @var array{translatedText?:string,error?:string} $data */
                        $data = $resp->toArray(false);
                        $translatedText = $data['translatedText'] ?? null;
                        $error = $data['error'] ?? $error;
                    }
                } else {
                    $translatedText = $useRules
                        ? $this->rules->translate($source, $target, $q, 'rules')
                        : $this->lookup->translateWordByWord($source, $target, $q);
                    dd($translatedText, $useRules);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $codes = $this->lookup->availableLanguageCodes();
        $languages = $this->codesToNames($codes);

        return $this->render('libre/index.html.twig', [
            'languages' => $languages,
            'prev_q' => $q,
            'prev_source' => $source,
            'prev_target' => $target,
            'translatedText' => $translatedText,
            'error' => $error,
            'prev_via_http' => $viaHttp ? 1 : 0,
            'prev_use_rules' => $useRules ? 1 : 0,
        ]);
    }

    /** @param string[] $codes */
    private function codesToNames(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $name = null;
            try {
                $name = Languages::getName($c);
            } catch (MissingResourceException) {
                // try 3→2 map
                $alpha2 = self::ISO3_TO_2[\strtolower($c)] ?? null;
                if ($alpha2) {
                    try {
                        $name = Languages::getName($alpha2);
                    } catch (\Throwable) {
                        $name = null;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }

            if (!$name) {
                $name = \strtoupper($c);
            }

            $out[$c] = $name;
        }
        \asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
}
