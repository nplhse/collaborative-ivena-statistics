<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageNavigationTreeBuilder;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageNavigationTreeBuilderTest extends KernelTestCase
{
    use Factories;

    public function testBuildNestsChildrenUnderParentSortedBySortOrderThenId(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(PageNavigationTreeBuilder::class);

        $root = PageFactory::createOne([
            'slug' => 'tree-root',
            'parent' => null,
        ])->_real();

        $later = PageFactory::createOne([
            'slug' => 'later',
            'parent' => $root,
            'sortOrder' => 20,
        ])->_real();

        $earlier = PageFactory::createOne([
            'slug' => 'earlier',
            'parent' => $root,
            'sortOrder' => 10,
        ])->_real();

        $tree = $builder->build([$root, $later, $earlier]);

        self::assertCount(1, $tree);
        self::assertSame($root->getId(), $tree[0]['page']->getId());

        $children = $tree[0]['children'];
        self::assertCount(2, $children);
        self::assertSame($earlier->getId(), $children[0]['page']->getId());
        self::assertSame($later->getId(), $children[1]['page']->getId());
        self::assertSame([], $children[0]['children']);
        self::assertSame([], $children[1]['children']);
    }

    public function testBuildSupportsNestedGrandchildren(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(PageNavigationTreeBuilder::class);

        $root = PageFactory::createOne(['slug' => 'r', 'parent' => null])->_real();
        $mid = PageFactory::createOne(['slug' => 'm', 'parent' => $root])->_real();
        $leaf = PageFactory::createOne(['slug' => 'l', 'parent' => $mid])->_real();

        $tree = $builder->build([$root, $leaf, $mid]);

        self::assertCount(1, $tree);
        $midNode = $tree[0]['children'][0];
        self::assertSame($mid->getId(), $midNode['page']->getId());
        self::assertCount(1, $midNode['children']);
        self::assertSame($leaf->getId(), $midNode['children'][0]['page']->getId());
    }
}
