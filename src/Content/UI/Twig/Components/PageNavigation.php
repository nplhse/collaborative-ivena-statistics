<?php

declare(strict_types=1);

namespace App\Content\UI\Twig\Components;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Application\Page\PageNavigationTree;
use App\Content\Application\Page\PageNavigationTreeProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Navigation through the editorial pages: the full tree or the subtree below one page.
 *
 * Renders nothing when no entry is visible. See docs/04-features/content/page-navigation.md.
 */
#[AsTwigComponent(name: 'Content:PageNavigation', template: '@Content/components/PageNavigation.html.twig')]
final class PageNavigation
{
    /** Root of the subtree to show; null shows the full tree. */
    public Page|PageKey|null $root = null;

    /** Show the root itself as the only top-level entry instead of starting with its children. */
    public bool $includeRoot = false;

    /** Heading; null uses the root title for a subtree and the generic title otherwise. */
    public ?string $title = null;

    /** Prefix for the HTML IDs of this instance; generated when empty. */
    public ?string $id = null;

    /** @var list<PageNavigationNode>|null */
    private ?array $nodes = null;

    private ?PageNavigationNode $rootNode = null;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly PageNavigationTreeProvider $treeProvider,
        private readonly PageNavigationTree $tree,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the Twig component system. */
    #[PostMount]
    public function assignId(): void
    {
        $this->getNavId();
    }

    /**
     * @return list<PageNavigationNode>
     */
    public function getNodes(): array
    {
        if (null !== $this->nodes) {
            return $this->nodes;
        }

        $visibleTree = $this->treeProvider->visibleTree();
        $rootPageId = null;
        if (null !== $this->root) {
            $this->rootNode = $this->resolveRoot($visibleTree, $this->root);
            if (!$this->rootNode instanceof PageNavigationNode) {
                return $this->nodes = [];
            }

            $rootPageId = $this->rootNode->pageId;
        }

        return $this->nodes = $this->tree->select(
            $visibleTree,
            $rootPageId,
            $this->includeRoot,
            $this->treeProvider->currentPageId(),
        );
    }

    /**
     * Explicit title, else the subtree root's title (when the root is not listed itself), else null.
     */
    public function getHeading(): ?string
    {
        if (null !== $this->title && '' !== trim($this->title)) {
            return $this->title;
        }

        $this->getNodes();
        if ($this->rootNode instanceof PageNavigationNode && !$this->includeRoot) {
            return $this->rootNode->title;
        }

        return null;
    }

    /**
     * The ID prefix of this instance; the generated one when no `id` was given.
     */
    public function getNavId(): string
    {
        if (null === $this->id || '' === trim($this->id)) {
            $this->id = $this->treeProvider->nextInstanceId();
        }

        return $this->id;
    }

    public function getHeadingId(): string
    {
        return $this->getNavId().'-title';
    }

    /**
     * @param list<PageNavigationNode> $visibleTree
     */
    private function resolveRoot(array $visibleTree, Page|PageKey $root): ?PageNavigationNode
    {
        if ($root instanceof PageKey) {
            return $this->tree->findByKey($visibleTree, $root);
        }

        $pageId = $root->getId();

        return null === $pageId ? null : $this->tree->find($visibleTree, $pageId);
    }
}
