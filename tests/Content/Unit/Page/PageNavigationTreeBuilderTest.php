<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageNavigationTreeBuilder;
use App\Content\Domain\Entity\Page;
use PHPUnit\Framework\TestCase;

final class PageNavigationTreeBuilderTest extends TestCase
{
    public function testOrdersSiblingsBySortOrderThenId(): void
    {
        $root = $this->page(1, 0);
        $second = $this->page(2, 1, $root);
        $first = $this->page(3, 0, $root);
        $tieLowId = $this->page(4, 5, $root);
        $tieHighId = $this->page(5, 5, $root);

        $tree = new PageNavigationTreeBuilder()->build([$tieHighId, $root, $second, $tieLowId, $first]);

        self::assertCount(1, $tree);
        self::assertSame(
            [3, 2, 4, 5],
            array_map(static fn (array $node): ?int => $node['page']->getId(), $tree[0]['children']),
        );
    }

    public function testDropsPagesWhoseParentIsNotInTheList(): void
    {
        $hiddenParent = $this->page(1, 0);
        $orphan = $this->page(2, 0, $hiddenParent);
        $visible = $this->page(3, 1);

        $tree = new PageNavigationTreeBuilder()->build([$orphan, $visible]);

        self::assertSame([3], array_map(static fn (array $node): ?int => $node['page']->getId(), $tree));
        self::assertSame([], $tree[0]['children']);
    }

    public function testUsesDisplayTitleAndPath(): void
    {
        $page = $this->page(1, 0);

        $tree = new PageNavigationTreeBuilder()->build([$page], [1 => ['title' => 'Über uns', 'path' => '/ueber-uns']]);

        self::assertSame('Über uns', $tree[0]['title']);
        self::assertSame('/ueber-uns', $tree[0]['path']);
    }

    private function page(int $id, int $sortOrder, ?Page $parent = null): Page
    {
        $page = new Page()->setSortOrder($sortOrder)->setParent($parent);
        new \ReflectionProperty(Page::class, 'id')->setValue($page, $id);

        return $page;
    }
}
