<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Doctrine;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PagePathSubscriberTest extends KernelTestCase
{
    use Factories;

    public function testChildPageGetsHierarchicalPathOnFlush(): void
    {
        self::bootKernel();

        $parent = PageFactory::createOne([
            'slug' => 'segment-parent',
            'parent' => null,
        ]);

        $child = PageFactory::createOne([
            'slug' => 'segment-child',
            'parent' => $parent,
        ]);

        self::assertSame('/segment-parent', $parent->getPath());
        self::assertSame('/segment-parent/segment-child', $child->getPath());
    }

    public function testDescendantPathsRecomputeWhenRootSlugChanges(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $token = bin2hex(random_bytes(4));
        $rootSlug = 'r-'.$token;
        $midSlug = 'm-'.$token;
        $leafSlug = 'l-'.$token;

        $root = PageFactory::createOne(['slug' => $rootSlug, 'parent' => null]);
        $mid = PageFactory::createOne(['slug' => $midSlug, 'parent' => $root]);
        $leaf = PageFactory::createOne(['slug' => $leafSlug, 'parent' => $mid]);

        $rootId = $root->getId();
        $midId = $mid->getId();
        $leafId = $leaf->getId();

        self::assertNotNull($rootId);
        self::assertNotNull($midId);
        self::assertNotNull($leafId);

        self::assertSame(
            sprintf('/%s/%s/%s', $rootSlug, $midSlug, $leafSlug),
            $leaf->getPath(),
        );

        $em->clear();

        $rootReloaded = $em->find(Page::class, $rootId);
        self::assertInstanceOf(Page::class, $rootReloaded);
        $rootReloaded->setSlug('x-'.$rootSlug);
        $em->flush();

        $midReloaded = $em->find(Page::class, $midId);
        $leafReloaded = $em->find(Page::class, $leafId);

        self::assertInstanceOf(Page::class, $midReloaded);
        self::assertInstanceOf(Page::class, $leafReloaded);

        self::assertSame(
            sprintf('/x-%s/%s', $rootSlug, $midSlug),
            $midReloaded->getPath(),
        );
        self::assertSame(
            sprintf('/x-%s/%s/%s', $rootSlug, $midSlug, $leafSlug),
            $leafReloaded->getPath(),
        );
    }
}
