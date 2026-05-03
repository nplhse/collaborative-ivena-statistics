<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Doctrine;

use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PagePathSubscriberTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChildPageGetsHierarchicalPathOnFlush(): void
    {
        self::bootKernel();

        $parent = PageFactory::createOne([
            'slug' => 'segment-parent',
            'parent' => null,
        ])->_real();

        $child = PageFactory::createOne([
            'slug' => 'segment-child',
            'parent' => $parent,
        ])->_real();

        self::assertSame('/segment-parent', $parent->getPath());
        self::assertSame('/segment-parent/segment-child', $child->getPath());
    }
}
