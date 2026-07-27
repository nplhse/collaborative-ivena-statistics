<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RobotsTxtController extends AbstractController
{
    #[Route('/robots.txt', name: 'app_robots_txt', methods: ['GET'])]
    public function __invoke(): Response
    {
        $content = $this->renderView('@Content/sitemap/robots.txt.twig');

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
