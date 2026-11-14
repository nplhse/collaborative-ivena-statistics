<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Navigation\DTO\FooterNavigationColumn;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\FooterNavigationProvider;
use App\Shared\Application\Navigation\SitemapProvider;

final readonly class NavigationExtension
{
    public function __construct(
        private FooterNavigationProvider $footerNavigationProvider,
        private SitemapProvider $sitemapProvider,
    ) {
    }

    /**
     * @return list<FooterNavigationColumn>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'footer_nav_columns')]
    public function footerNavColumns(): array
    {
        return $this->footerNavigationProvider->getColumns();
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
