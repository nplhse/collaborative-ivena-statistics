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
     * @param list<Page> $pages
     *
     * @return array<int, array{page: Page, children: array<int, mixed>}>
     */
    public function build(array $pages): array
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

        return $this->buildRecursive($byParentId, 0);
    }

    /**
     * @param array<int, list<Page>> $byParentId
     *
     * @return array<int, array{page: Page, children: array<int, mixed>}>
     */
    private function buildRecursive(array $byParentId, int $parentKey): array
    {
        if (!isset($byParentId[$parentKey])) {
            return [];
        }

        $nodes = [];
        foreach ($byParentId[$parentKey] as $page) {
            $id = $page->getId();
            $childKey = $id ?? 0;
            $nodes[] = [
                'page' => $page,
                'children' => $this->buildRecursive($byParentId, $childKey),
            ];
        }

        return $nodes;
    }
}
