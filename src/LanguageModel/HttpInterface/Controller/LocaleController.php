<?php

declare(strict_types=1);

namespace App\LanguageModel\HttpInterface\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LocaleController
{
    #[Route('/switch-language/{locale}', name: 'switch_language', methods: ['GET'])]
    public function switch(string $locale, Request $request): Response
    {
        $response = new RedirectResponse(
            $request->headers->get('Referer', '/'),
            Response::HTTP_FOUND,
        );

        $response->headers->setCookie(
            new Cookie(
                name: 'lang',
                value: $locale,
                expire: time() + 365 * 24 * 3600,
                path: '/',
            )
        );

        return $response;
    }
}
