<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Page\DTO\PageNavigationNode;
use App\Content\Domain\Enum\PageKey;

/**
 * Selects the part of the visible page tree that one navigation shows and marks the current page.
 *
 * Works only on already visible nodes, so a root or current page the viewer cannot see is never found.
 */
final class PageNavigationTree
{
    /**
     * @param list<PageNavigationNode> $nodes
     */
    public function find(array $nodes, int $pageId): ?PageNavigationNode
    {
        foreach ($nodes as $node) {
            if ($node->pageId === $pageId) {
                return $node;
            }

            $found = $this->find($node->children, $pageId);
            if ($found instanceof PageNavigationNode) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<PageNavigationNode> $nodes
     */
    public function findByKey(array $nodes, PageKey $key): ?PageNavigationNode
    {
        foreach ($nodes as $node) {
            if ($node->key === $key) {
                return $node;
            }

            $found = $this->findByKey($node->children, $key);
            if ($found instanceof PageNavigationNode) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Returns the entries of one navigation.
     *
     * - Without a root: the full tree.
     * - With a root: its children, or the root itself as the only (expanded) entry when $includeRoot is true.
     *   A root that is not in $nodes gives an empty list.
     *
     * The current page and its ancestors are marked and expanded only when the current page is part of
     * the selected entries; otherwise nothing is marked.
     *
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<PageNavigationNode>
     */
    public function select(array $nodes, ?int $rootPageId, bool $includeRoot, ?int $currentPageId): array
    {
        $selected = $nodes;
        if (null !== $rootPageId) {
            $root = $this->find($nodes, $rootPageId);
            if (!$root instanceof PageNavigationNode) {
                return [];
            }

            $selected = $includeRoot ? [$root] : $root->children;
        }

        $path = null === $currentPageId ? [] : $this->pathTo($selected, $currentPageId);
        $alwaysExpanded = null !== $rootPageId && $includeRoot ? $rootPageId : null;

        return $this->mark($selected, $path, $currentPageId, $alwaysExpanded);
    }

    /**
     * Page IDs from the top level down to $pageId, or an empty list when it is not in $nodes.
     *
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<int>
     */
    private function pathTo(array $nodes, int $pageId): array
    {
        foreach ($nodes as $node) {
            if ($node->pageId === $pageId) {
                return [$pageId];
            }

            $childPath = $this->pathTo($node->children, $pageId);
            if ([] !== $childPath) {
                return [$node->pageId, ...$childPath];
            }
        }

        return [];
    }

    /**
     * @param list<PageNavigationNode> $nodes
     * @param list<int>                $path
     *
     * @return list<PageNavigationNode>
     */
    private function mark(array $nodes, array $path, ?int $currentPageId, ?int $alwaysExpandedPageId): array
    {
        $marked = [];
        foreach ($nodes as $node) {
            $onPath = \in_array($node->pageId, $path, true);
            $current = $onPath && $node->pageId === $currentPageId;

            $marked[] = $node->withState(
                children: $this->mark($node->children, $path, $currentPageId, $alwaysExpandedPageId),
                current: $current,
                ancestor: $onPath && !$current,
                expanded: $node->hasChildren() && ($onPath || $node->pageId === $alwaysExpandedPageId),
            );
        }

        return $marked;
    }
}
