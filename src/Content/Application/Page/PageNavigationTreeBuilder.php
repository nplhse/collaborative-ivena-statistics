<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Page;

/**
 * Builds a nested array structure for Twig from a flat list of pages.
 *
 * Children arrays repeat the same shape recursively (Twig-only consumption).
 */
final class PageNavigationTreeBuilder
{
    /**
     * @param list<Page>                                     $pages
     * @param array<int, array{title: string, path: string}> $displayByPageId
     *
     * @return array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}>
     */
    public function build(array $pages, array $displayByPageId = []): array
    {
        /** @var array<int, list<Page>> $byParentId */
        $byParentId = [];
        foreach ($pages as $page) {
            $key = $page->getParent()?->getId() ?? 0;
            $byParentId[$key][] = $page;
        }

        foreach ($byParentId as &$group) {
            usort(
                $group,
                static fn (Page $a, Page $b): int => [$a->getSortOrder(), $a->getId() ?? 0] <=> [$b->getSortOrder(), $b->getId() ?? 0],
            );
        }
        unset($group);

        return $this->buildRecursive($byParentId, $displayByPageId, 0);
    }

    /**
     * @param array<int, list<Page>>                         $byParentId
     * @param array<int, array{title: string, path: string}> $displayByPageId
     *
     * @return array<int, array{page: Page, title: string, path: string, children: array<int, mixed>}>
     */
    private function buildRecursive(array $byParentId, array $displayByPageId, int $parentKey): array
    {
        if (!isset($byParentId[$parentKey])) {
            return [];
        }

        $nodes = [];
        foreach ($byParentId[$parentKey] as $page) {
            $id = $page->getId();
            $childKey = $id ?? 0;
            $display = null !== $id ? ($displayByPageId[$id] ?? null) : null;
            $nodes[] = [
                'page' => $page,
                'title' => $display['title'] ?? '',
                'path' => $display['path'] ?? '',
                'children' => $this->buildRecursive($byParentId, $displayByPageId, $childKey),
            ];
        }

        return $nodes;
    }
}
