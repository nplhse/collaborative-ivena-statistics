<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Content\Infrastructure\Repository\PostRepository;

final readonly class PageSidebarDataProvider
{
    public function __construct(
        private PageRepository $pageRepository,
        private PageAccessChecker $pageAccessChecker,
        private PageNavigationTreeBuilder $pageNavigationTreeBuilder,
        private PostRepository $postRepository,
    ) {
    }

    /**
     * @return array{
     *   pageTree: array<int, array{page: Page, children: array<int, mixed>}>,
     *   latest_posts: list<\App\Content\Domain\Entity\Post>
     * }
     */
    public function getData(): array
    {
        $pages = array_values(array_filter(
            $this->pageRepository->findAllPublished(),
            $this->pageAccessChecker->canView(...),
        ));

        return [
            'pageTree' => $this->pageNavigationTreeBuilder->build($pages),
            'latest_posts' => $this->postRepository->findPublishedForIndex(5),
        ];
    }
}
