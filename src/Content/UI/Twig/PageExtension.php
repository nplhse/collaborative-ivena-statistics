<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Page\DTO\PageImagePresentation;
use App\Content\Application\Page\DTO\PageNavigationLink;
use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageImageBlockPresenter;
use App\Content\Application\Page\PageNavigationProvider;
use App\Content\Application\Page\PageNavigationTree;
use App\Content\Application\Page\PageNavigationTreeProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageContentBlockType;
use App\Content\Domain\Enum\PageKey;

final readonly class PageExtension
{
    public function __construct(
        private PageNavigationProvider $pageNavigationProvider,
        private PageImageBlockPresenter $pageImageBlockPresenter,
        private PageNavigationTreeProvider $pageNavigationTreeProvider,
        private PageNavigationTree $pageNavigationTree,
    ) {
    }

    /**
     * Visible subpages of the page being shown, in navigation order (#1578); empty outside a page view.
     *
     * @return list<PageNavigationNode>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'page_children')]
    public function pageChildren(): array
    {
        $pageId = $this->pageNavigationTreeProvider->currentPageId();
        if (null === $pageId) {
            return [];
        }

        return $this->pageNavigationTree->find($this->pageNavigationTreeProvider->visibleTree(), $pageId)->children ?? [];
    }

    /** The visible page a link of the "Learn more" block points to (#1590), or null for other addresses. */
    #[\Twig\Attribute\AsTwigFunction(name: 'page_at_url')]
    public function pageAtUrl(string $url): ?PageNavigationNode
    {
        return $this->pageNavigationTree->findByUrl($this->pageNavigationTreeProvider->visibleTree(), $url);
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
