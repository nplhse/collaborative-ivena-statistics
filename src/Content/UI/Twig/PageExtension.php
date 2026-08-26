<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Page\DTO\PageImagePresentation;
use App\Content\Application\Page\DTO\PageNavigationLink;
use App\Content\Application\Page\PageImageBlockPresenter;
use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageContentBlockType;
use App\Content\Domain\Enum\PageKey;

final readonly class PageExtension
{
    public function __construct(
        private PageNavigationProvider $pageNavigationProvider,
        private PageImageBlockPresenter $pageImageBlockPresenter,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'page_image_presentation')]
    public function pageImagePresentation(array $data): PageImagePresentation
    {
        return $this->pageImageBlockPresenter->present($data);
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'page_by_key')]
    public function pageByKey(string $key): ?Page
    {
        $pageKey = PageKey::tryFrom($key);
        if (!$pageKey instanceof PageKey) {
            return null;
        }

        return $this->pageNavigationProvider->getPageByKey($pageKey);
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'page_url_by_key')]
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
    #[\Twig\Attribute\AsTwigFunction(name: 'page_nav_header_items')]
    public function pageNavHeaderItems(): array
    {
        return $this->pageNavigationProvider->getHeaderPages();
    }

    /**
     * @return list<PageNavigationLink>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'page_nav_footer_items')]
    public function pageNavFooterItems(): array
    {
        return $this->pageNavigationProvider->getFooterPages();
    }

    /**
     * @return list<string>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'page_content_block_types')]
    public function pageContentBlockTypes(): array
    {
        return PageContentBlockType::values();
    }
}
