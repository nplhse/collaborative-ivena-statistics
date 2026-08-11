<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Doctrine;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Locale\SupportedLocales;
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

        self::assertSame('/segment-parent', $parent->translation(SupportedLocales::DEFAULT)?->getPath());
        self::assertSame('/segment-parent/segment-child', $child->translation(SupportedLocales::DEFAULT)?->getPath());
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
            $leaf->translation(SupportedLocales::DEFAULT)?->getPath(),
        );

        $em->clear();

        $rootReloaded = $em->find(Page::class, $rootId);
        self::assertInstanceOf(Page::class, $rootReloaded);
        $rootTranslation = $rootReloaded->translation(SupportedLocales::DEFAULT);
        self::assertInstanceOf(PageTranslation::class, $rootTranslation);
        $rootTranslation->setSlug('x-'.$rootSlug);
        $em->flush();

        $midReloaded = $em->find(Page::class, $midId);
        $leafReloaded = $em->find(Page::class, $leafId);

        self::assertInstanceOf(Page::class, $midReloaded);
        self::assertInstanceOf(Page::class, $leafReloaded);

        self::assertSame(
            sprintf('/x-%s/%s', $rootSlug, $midSlug),
            $midReloaded->translation(SupportedLocales::DEFAULT)?->getPath(),
        );
        self::assertSame(
            sprintf('/x-%s/%s/%s', $rootSlug, $midSlug, $leafSlug),
            $leafReloaded->translation(SupportedLocales::DEFAULT)?->getPath(),
        );
    }

    public function testParentChangeRecomputesChildPaths(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $parentA = PageFactory::createOne(['slug' => 'parent-a', 'parent' => null]);
        $parentB = PageFactory::createOne(['slug' => 'parent-b', 'parent' => null]);
        $child = PageFactory::createOne(['slug' => 'moved-child', 'parent' => $parentA]);

        self::assertSame('/parent-a/moved-child', $child->translation(SupportedLocales::DEFAULT)?->getPath());

        $childId = $child->getId();
        self::assertNotNull($childId);
        $em->clear();

        $childReloaded = $em->find(Page::class, $childId);
        $parentBReloaded = $em->find(Page::class, $parentB->getId());
        self::assertInstanceOf(Page::class, $childReloaded);
        self::assertInstanceOf(Page::class, $parentBReloaded);

        $childReloaded->setParent($parentBReloaded);
        $em->flush();

        $em->clear();
        $moved = $em->find(Page::class, $childId);
        self::assertInstanceOf(Page::class, $moved);
        self::assertSame('/parent-b/moved-child', $moved->translation(SupportedLocales::DEFAULT)?->getPath());
    }

    public function testMissingParentTranslationIsIgnoredOnFlush(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $parent = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'parent-en-only',
            'path' => '/parent-en-only-legacy',
        ]);
        $parentEn = new PageTranslation()
            ->setLocale(SupportedLocales::DEFAULT)
            ->setTitle('Parent EN')
            ->setSlug('parent-en-only')
            ->setPath('/parent-en-only')
            ->setStatus(PageTranslation::STATUS_PUBLISHED)
            ->setContent([]);
        $parent->addTranslation($parentEn);
        $em->persist($parentEn);
        $em->flush();

        $child = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'child-de',
            'path' => '/child-de-legacy',
            'parent' => $parent,
        ]);
        $childDe = new PageTranslation()
            ->setLocale(SupportedLocales::GERMAN)
            ->setTitle('Kind DE')
            ->setSlug('kind-de')
            ->setPath('/kind-de-stale')
            ->setStatus(PageTranslation::STATUS_DRAFT)
            ->setContent([]);
        $child->addTranslation($childDe);
        $em->persist($childDe);
        $em->flush();

        $childId = $child->getId();
        self::assertNotNull($childId);
        $em->clear();

        $childReloaded = $em->find(Page::class, $childId);
        self::assertInstanceOf(Page::class, $childReloaded);
        $translation = $childReloaded->translation(SupportedLocales::GERMAN);
        self::assertInstanceOf(PageTranslation::class, $translation);
        $translation->setSlug('kind-de-updated');
        $em->flush();

        $em->clear();
        $after = $em->find(Page::class, $childId);
        self::assertInstanceOf(Page::class, $after);
        self::assertSame('/kind-de-stale', $after->translation(SupportedLocales::GERMAN)?->getPath());
    }
}
