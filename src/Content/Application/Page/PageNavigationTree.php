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
    /** Legal pages: linked in the footer, so the full tree leaves them out (#1582). */
    private const array FOOTER_ONLY_KEYS = [PageKey::Imprint, PageKey::Privacy, PageKey::Terms];

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
     * - Without a root: the full tree without help articles (#596) and without the legal pages of the footer
     *   (#1582); the help start page stays, its articles are shown by the help navigation.
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
        if (null === $rootPageId) {
            $selected = $this->withoutHelpArticlesAndFooterPages($nodes);
        } else {
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
     * Help articles in display order (depth first) below the help start page, for previous and next links.
     *
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<PageNavigationNode>
     */
    public function helpArticles(array $nodes): array
    {
        $root = $this->findByKey($nodes, PageKey::Help);

        return $root instanceof PageNavigationNode ? $this->flatten($root->children) : [];
    }

    /**
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<PageNavigationNode>
     */
    private function flatten(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            $flat[] = $node;
            $flat = [...$flat, ...$this->flatten($node->children)];
        }

        return $flat;
    }

    /**
     * @param list<PageNavigationNode> $nodes
     *
     * @return list<PageNavigationNode>
     */
    private function withoutHelpArticlesAndFooterPages(array $nodes): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if ($node->help || \in_array($node->key, self::FOOTER_ONLY_KEYS, true)) {
                continue;
            }

            $kept[] = $node->withState($this->withoutHelpArticlesAndFooterPages($node->children), $node->current, $node->ancestor, $node->expanded);
        }

        return $kept;
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
