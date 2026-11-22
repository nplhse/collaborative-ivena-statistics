<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Post;
use App\Content\Infrastructure\Repository\PostRepository;

/**
 * Sidebar data of a page that is not part of the page navigation component.
 */
final readonly class PageSidebarDataProvider
{
    public function __construct(
        private PostRepository $postRepository,
    ) {
    }

    /**
     * @return array{latest_posts: list<Post>}
     */
    public function getData(): array
    {
        return [
            'latest_posts' => $this->postRepository->findPublishedForIndex(5),
        ];
    }
}
