<?php

declare(strict_types=1);

namespace App\Content\Application\Help;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageNavigationTree;
use App\Content\Application\Page\PageNavigationTreeProvider;
use App\Content\Domain\Enum\PageKey;

/**
 * The help section as the viewer sees it (#596), taken from the visible page tree: the main topics of the start page,
 * the articles in reading order and the neighbours of an article. Unpublished or hidden articles never appear.
 */
final readonly class HelpNavigation
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private PageNavigationTreeProvider $treeProvider,
        private PageNavigationTree $tree,
    ) {
    }

    /** The help start page, or null when it is missing, unpublished or hidden from the viewer. */
    public function root(): ?PageNavigationNode
    {
        return $this->tree->findByKey($this->treeProvider->visibleTree(), PageKey::Help);
    }

    /**
     * Main topics: the articles directly below the start page, each with its articles.
     *
     * @return list<PageNavigationNode>
     */
    public function topics(): array
    {
        return $this->root()->children ?? [];
    }

    /**
     * Every visible article in reading order (depth first).
     *
     * @return list<PageNavigationNode>
     */
    public function articles(): array
    {
        return $this->tree->helpArticles($this->treeProvider->visibleTree());
    }

    /**
     * The articles before and after $pageId in reading order.
     *
     * @return array{previous: ?PageNavigationNode, next: ?PageNavigationNode}
     */
    public function neighbours(int $pageId): array
    {
        $articles = $this->articles();
        foreach ($articles as $index => $article) {
            if ($article->pageId === $pageId) {
                return [
                    'previous' => $index > 0 ? $articles[$index - 1] : null,
                    'next' => $articles[$index + 1] ?? null,
                ];
            }
        }

        return ['previous' => null, 'next' => null];
    }
}
