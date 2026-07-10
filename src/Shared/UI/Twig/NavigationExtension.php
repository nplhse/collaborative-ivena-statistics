<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Navigation\DTO\FooterNavigationColumn;
use App\Shared\Application\Navigation\DTO\SitemapSection;
use App\Shared\Application\Navigation\FooterNavigationProvider;
use App\Shared\Application\Navigation\SitemapProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly FooterNavigationProvider $footerNavigationProvider,
        private readonly SitemapProvider $sitemapProvider,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('footer_nav_columns', $this->footerNavColumns(...)),
            new TwigFunction('sitemap_sections', $this->sitemapSections(...)),
        ];
    }

    /**
     * @return list<FooterNavigationColumn>
     */
    public function footerNavColumns(): array
    {
        return $this->footerNavigationProvider->getColumns();
    }

    /**
     * @return list<SitemapSection>
     */
    public function sitemapSections(): array
    {
        return $this->sitemapProvider->getSections();
    }
}
