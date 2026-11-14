<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\SitemapProvider;

final readonly class NavigationExtension
{
    public function __construct(
        private SitemapProvider $sitemapProvider,
    ) {
    }

    /**
     * @return list<SitemapSection>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'sitemap_sections')]
    public function sitemapSections(): array
    {
        return $this->sitemapProvider->getSections();
    }
}
