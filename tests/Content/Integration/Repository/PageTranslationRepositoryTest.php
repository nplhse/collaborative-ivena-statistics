<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Factory\PageTranslationFactory;
use App\Content\Infrastructure\Repository\PageTranslationRepository;
use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageTranslationRepositoryTest extends KernelTestCase
{
    use Factories;

    private PageTranslationRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = self::getContainer()->get(PageTranslationRepository::class);
    }

    public function testFindPublishedByPathReturnsOnlyPublishedMatches(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'repo-path-root',
            'path' => '/repo-path-root-legacy',
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'Live',
            'slug' => 'repo-path-live',
            'path' => '/repo-path-live',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'Draft',
            'slug' => 'repo-path-draft',
            'path' => '/repo-path-draft',
            'status' => PageTranslation::STATUS_DRAFT,
        ]);

        $live = $this->repo->findPublishedByPath('/repo-path-live');
        self::assertInstanceOf(PageTranslation::class, $live);
        self::assertSame('Live', $live->getTitle());

        self::assertNull($this->repo->findPublishedByPath('/repo-path-draft'));
        self::assertNull($this->repo->findPublishedByPath('/missing'));
    }

    public function testFindOneByPageAndLocaleAndPublishedVariant(): void
    {
        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'repo-locale-root',
            'path' => '/repo-locale-root-legacy',
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'EN Draft',
            'slug' => 'repo-locale-en',
            'path' => '/repo-locale-en',
            'status' => PageTranslation::STATUS_DRAFT,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'DE Live',
            'slug' => 'repo-locale-de',
            'path' => '/repo-locale-de',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $en = $this->repo->findOneByPageAndLocale($page, SupportedLocales::DEFAULT);
        self::assertInstanceOf(PageTranslation::class, $en);
        self::assertSame('EN Draft', $en->getTitle());

        self::assertNull($this->repo->findPublishedByPageAndLocale($page, SupportedLocales::DEFAULT));

        $de = $this->repo->findPublishedByPageAndLocale($page, SupportedLocales::GERMAN);
        self::assertInstanceOf(PageTranslation::class, $de);
        self::assertSame('DE Live', $de->getTitle());
    }

    public function testFindAllPublishedPublicExcludesAuthAndDraft(): void
    {
        PageFactory::createOne([
            'slug' => 'repo-public-live',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'repo-auth-live',
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        PageFactory::createOne([
            'slug' => 'repo-public-draft',
            'status' => PageTranslation::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $paths = array_map(
            static fn (PageTranslation $t): string => (string) $t->getPath(),
            $this->repo->findAllPublishedPublic(),
        );

        self::assertContains('/repo-public-live', $paths);
        self::assertNotContains('/repo-auth-live', $paths);
        self::assertNotContains('/repo-public-draft', $paths);
    }

    public function testCountByLocale(): void
    {
        $beforeEn = $this->repo->countByLocale(SupportedLocales::DEFAULT);
        $beforeDe = $this->repo->countByLocale(SupportedLocales::GERMAN);

        $page = PageFactory::new()->withoutDefaultTranslation()->create([
            'slug' => 'repo-count-root',
            'path' => '/repo-count-root-legacy',
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::DEFAULT,
            'title' => 'EN',
            'slug' => 'repo-count-en',
            'path' => '/repo-count-en',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        PageTranslationFactory::createOne([
            'page' => $page,
            'locale' => SupportedLocales::GERMAN,
            'title' => 'DE',
            'slug' => 'repo-count-de',
            'path' => '/repo-count-de',
            'status' => PageTranslation::STATUS_DRAFT,
        ]);

        self::assertSame($beforeEn + 1, $this->repo->countByLocale(SupportedLocales::DEFAULT));
        self::assertSame($beforeDe + 1, $this->repo->countByLocale(SupportedLocales::GERMAN));
    }

    public function testExistsSiblingSlugForRootAndChildPages(): void
    {
        $parent = PageFactory::createOne([
            'slug' => 'repo-sib-parent',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $firstChild = PageFactory::createOne([
            'slug' => 'repo-sib-child',
            'parent' => $parent,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $secondChild = PageFactory::createOne([
            'slug' => 'repo-sib-other',
            'parent' => $parent,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        $firstTranslationId = $firstChild->translation(SupportedLocales::DEFAULT)?->getId();
        self::assertNotNull($firstTranslationId);

        self::assertTrue($this->repo->existsSiblingSlug(
            $secondChild,
            SupportedLocales::DEFAULT,
            'repo-sib-child',
        ));

        self::assertFalse($this->repo->existsSiblingSlug(
            $secondChild,
            SupportedLocales::DEFAULT,
            'repo-sib-child',
            $firstTranslationId,
        ));

        self::assertFalse($this->repo->existsSiblingSlug(
            $secondChild,
            SupportedLocales::DEFAULT,
            'repo-sib-other',
        ));

        $rootA = PageFactory::createOne([
            'slug' => 'repo-root-a',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);
        $rootB = PageFactory::createOne([
            'slug' => 'repo-root-b',
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        self::assertFalse($this->repo->existsSiblingSlug($rootA, SupportedLocales::DEFAULT, 'repo-root-a'));
        self::assertFalse($this->repo->existsSiblingSlug($rootB, SupportedLocales::DEFAULT, 'repo-root-b'));
        self::assertTrue($this->repo->existsSiblingSlug($rootB, SupportedLocales::DEFAULT, 'repo-root-a'));
        self::assertFalse($this->repo->existsSiblingSlug($rootA, SupportedLocales::DEFAULT, 'totally-unique-root-slug'));
    }
}
