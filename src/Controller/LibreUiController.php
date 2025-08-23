<?php
// src/Controller/LibreUiController.php
declare(strict_types=1);

namespace App\Controller;

use App\Service\StarDictLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LibreUiController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private StarDictLookup $lookup,
    ) {}

    #[Route(path: '/', name: 'libre_ui', methods: ['GET'])]
    public function index(): Response
    {
        // The UI doesn't fetch languages dynamically to keep things snappy.
        // It mirrors the codes you'll likely load via app:load (add more as needed).
        $langCodes = [
            'afr' => 'Afrikaans',
            'eng' => 'English',
            'deu' => 'German',
            'spa' => 'Spanish',
            'fra' => 'French',
            'por' => 'Portuguese',
            'ita' => 'Italian',
        ];

        return $this->render('libre/index.html.twig', [
            'languages' => $langCodes,
        ]);
    }

    #[Route(path: '/ui/translate', name: 'libre_ui_translate', methods: ['POST'])]
    public function translate(Request $request): Response
    {
        $q = (string)$request->request->get('q', '');
        $source = (string)$request->request->get('source', '');
        $target = (string)$request->request->get('target', '');

        $error = null;
        $translatedText = null;

        if ($q === '' || $source === '' || $target === '') {
            $error = 'Please provide text, source, and target.';
        } else {

            try {
                $translatedText = $this->lookup->translateWordByWord($source, $target, $q);
            } catch (\Throwable $e) {
                return $this->json(['error' => $e->getMessage()], 500);
            }

            if (0)
            try {
                // Call our own /translate endpoint, just like an external client would.
                $resp = $this->http->request('POST', $request->getHttpHost() . '/translate', [
                    'json' => [
                        'q' => $q,
                        'source' => $source,
                        'target' => $target,
                    ],
                    'timeout' => 20,
                ]);

                if ($resp->getStatusCode() !== 200) {
                    $error = 'Translation API returned HTTP ' . $resp->getStatusCode();
                } else {
                    /** @var array{translatedText?:string,error?:string} $data */
                    $data = $resp->toArray(false);
                    if (isset($data['error'])) {
                        $error = $data['error'];
                    } else {
                        $translatedText = $data['translatedText'] ?? null;
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // Re-render the same page with results.
        // Keep the posted values so the user can iterate quickly.
        $langCodes = [
            'afr' => 'Afrikaans',
            'eng' => 'English',
            'deu' => 'German',
            'spa' => 'Spanish',
            'fra' => 'French',
            'por' => 'Portuguese',
            'ita' => 'Italian',
        ];

        return $this->render('libre/index.html.twig', [
            'languages' => $langCodes,
            'prev_q' => $q,
            'prev_source' => $source,
            'prev_target' => $target,
            'translatedText' => $translatedText,
            'error' => $error,
        ]);
    }
}
