<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Page\DTO\PageNavigationLink;
use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageContentBlockType;
use App\Content\Domain\Enum\PageKey;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PageExtension extends AbstractExtension
{
    public function __construct(
        private readonly PageNavigationProvider $pageNavigationProvider,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('page_by_key', $this->pageByKey(...)),
            new TwigFunction('page_url_by_key', $this->pageUrlByKey(...)),
            new TwigFunction('page_nav_header_items', $this->pageNavHeaderItems(...)),
            new TwigFunction('page_nav_footer_items', $this->pageNavFooterItems(...)),
            new TwigFunction('page_content_block_types', $this->pageContentBlockTypes(...)),
        ];
    }

    public function pageByKey(string $key): ?Page
    {
        $pageKey = PageKey::tryFrom($key);
        if (!$pageKey instanceof PageKey) {
            return null;
        }

        return $this->pageNavigationProvider->getPageByKey($pageKey);
    }

    public function pageUrlByKey(string $key): ?string
    {
        $pageKey = PageKey::tryFrom($key);
        if (!$pageKey instanceof PageKey) {
            return null;
        }

        return $this->pageNavigationProvider->getUrlByKey($pageKey);
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function pageNavHeaderItems(): array
    {
        return $this->pageNavigationProvider->getHeaderPages();
    }

    /**
     * @return list<PageNavigationLink>
     */
    public function pageNavFooterItems(): array
    {
        return $this->pageNavigationProvider->getFooterPages();
    }

    /**
     * @return list<string>
     */
    public function pageContentBlockTypes(): array
    {
        return PageContentBlockType::values();
    }
}
