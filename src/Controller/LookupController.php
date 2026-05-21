<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DbLookupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class LookupController extends AbstractController
{
    public function __construct(private readonly DbLookupService $lookup) {}

    #[Route('/lookup', name: 'app_lookup', methods: ['GET'])]
    public function __invoke(
        #[MapQueryParameter] string $word = '',
        #[MapQueryParameter] string $lang = '',
    ): Response {
        $languages = $this->lookup->availableLanguageCodes();
        $results = [];

        if ($word !== '' && $lang !== '') {
            try {
                $results = $this->lookup->resolve($lang, $word);
            } catch (\RuntimeException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->render('lookup/index.html.twig', [
            'word'      => $word,
            'lang'      => $lang,
            'languages' => $languages,
            'results'   => $results,
        ]);
    }
}
