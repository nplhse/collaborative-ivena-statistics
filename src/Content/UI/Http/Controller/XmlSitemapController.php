<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Sitemap\XmlSitemapProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class XmlSitemapController extends AbstractController
{
    public function __construct(
        private readonly XmlSitemapProvider $xmlSitemapProvider,
    ) {
    }

    #[Route('/sitemap.xml', name: 'app_xml_sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        $xml = $this->renderView('@Content/sitemap/sitemap.xml.twig', [
            'urls' => $this->xmlSitemapProvider->getUrls(),
        ]);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
