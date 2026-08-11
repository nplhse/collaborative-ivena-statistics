<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PageRepositoryQueryTest extends KernelTestCase
{
    use Factories;

    private PageRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = self::getContainer()->get(PageRepository::class);
    }

    public function testFindAllWithPublishedTranslationExcludesDraft(): void
    {
        PageFactory::createOne([
            'slug' => 'all-draft',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'all-published',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $slugs = array_map(
            static fn (Page $p): ?string => $p->translation('en')?->getSlug(),
            $this->repo->findAllWithPublishedTranslation(),
        );

        self::assertContains('all-published', $slugs);
        self::assertNotContains('all-draft', $slugs);
    }

    public function testFindAllWithPublishedTranslationVisibleToAuthenticatedUserExcludesDraft(): void
    {
        PageFactory::createOne([
            'slug' => 'draft-only',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'published-public',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $slugs = array_map(
            static fn (Page $p): ?string => $p->translation('en')?->getSlug(),
            $this->repo->findAllWithPublishedTranslationVisibleToAuthenticatedUser(),
        );

        self::assertContains('published-public', $slugs);
        self::assertNotContains('draft-only', $slugs);
    }

    public function testFindAllWithPublishedTranslationVisibleToAuthenticatedUserIncludesPublicAndAuthenticatedVisibility(): void
    {
        PageFactory::createOne([
            'slug' => 'vis-public',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'vis-auth',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $slugs = array_map(
            static fn (Page $p): ?string => $p->translation('en')?->getSlug(),
            $this->repo->findAllWithPublishedTranslationVisibleToAuthenticatedUser(),
        );

        self::assertContains('vis-public', $slugs);
        self::assertContains('vis-auth', $slugs);
    }

    public function testFindAllWithPublishedTranslationPublicOnlyReturnsPublishedPublicPages(): void
    {
        PageFactory::createOne([
            'slug' => 'public-live',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'auth-only',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        PageFactory::createOne([
            'slug' => 'public-draft',
            'status' => Page::STATUS_DRAFT,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        $slugs = array_map(
            static fn (Page $p): ?string => $p->translation('en')?->getSlug(),
            $this->repo->findAllWithPublishedTranslationPublic(),
        );

        self::assertContains('public-live', $slugs);
        self::assertNotContains('auth-only', $slugs);
        self::assertNotContains('public-draft', $slugs);
    }

    public function testFindOneWithPublishedTranslationByKeyReturnsPublishedPage(): void
    {
        PageFactory::createOne([
            'slug' => 'about-live',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $page = $this->repo->findOneWithPublishedTranslationByKey(PageKey::About);

        self::assertNotNull($page);
        $translation = $page->translation('en');
        self::assertInstanceOf(PageTranslation::class, $translation);
        self::assertSame('about-live', $translation->getSlug());
        self::assertSame('/about-live', $translation->getPath());
    }

    public function testFindOneWithPublishedTranslationByKeyReturnsNullWhenOnlyDraftExists(): void
    {
        PageFactory::createOne([
            'slug' => 'faq-draft-only',
            'path' => '/faq-draft-only',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_DRAFT,
        ]);

        self::assertNull($this->repo->findOneWithPublishedTranslationByKey(PageKey::Faq));
    }

    public function testPageCanExistWithoutKey(): void
    {
        PageFactory::createOne([
            'slug' => 'generic-page',
            'path' => '/generic-page',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
        ]);

        self::assertNull($this->repo->findOneWithPublishedTranslationByKey(PageKey::About));
    }
}
