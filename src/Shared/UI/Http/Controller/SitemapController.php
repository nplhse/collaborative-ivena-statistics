<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use App\Shared\Application\Navigation\SitemapProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly SitemapProvider $sitemapProvider,
    ) {
    }

    #[Route('/sitemap', name: 'app_sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@Shared/sitemap/index.html.twig', [
            'sections' => $this->sitemapProvider->getSections(),
        ]);
    }
}
