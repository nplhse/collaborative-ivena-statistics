<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\Page;
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

    public function testFindAllPublishedExcludesDraft(): void
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
            static fn (Page $p): ?string => $p->getSlug(),
            $this->repo->findAllPublished(),
        );

        self::assertContains('all-published', $slugs);
        self::assertNotContains('all-draft', $slugs);
    }

    public function testFindAllPublishedVisibleToAuthenticatedUserExcludesDraft(): void
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
            static fn (Page $p): ?string => $p->getSlug(),
            $this->repo->findAllPublishedVisibleToAuthenticatedUser(),
        );

        self::assertContains('published-public', $slugs);
        self::assertNotContains('draft-only', $slugs);
    }

    public function testFindAllPublishedVisibleToAuthenticatedUserIncludesPublicAndAuthenticatedVisibility(): void
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
            static fn (Page $p): ?string => $p->getSlug(),
            $this->repo->findAllPublishedVisibleToAuthenticatedUser(),
        );

        self::assertContains('vis-public', $slugs);
        self::assertContains('vis-auth', $slugs);
    }

    public function testFindOnePublishedByKeyReturnsPublishedPage(): void
    {
        PageFactory::createOne([
            'slug' => 'about-live',
            'key' => PageKey::About,
            'status' => Page::STATUS_PUBLISHED,
        ]);

        $page = $this->repo->findOnePublishedByKey(PageKey::About);

        self::assertNotNull($page);
        self::assertSame('about-live', $page->getSlug());
        self::assertSame('/about-live', $page->getPath());
    }

    public function testFindOnePublishedByKeyReturnsNullWhenOnlyDraftExists(): void
    {
        PageFactory::createOne([
            'slug' => 'faq-draft-only',
            'path' => '/faq-draft-only',
            'key' => PageKey::Faq,
            'status' => Page::STATUS_DRAFT,
        ]);

        self::assertNull($this->repo->findOnePublishedByKey(PageKey::Faq));
    }

    public function testPageCanExistWithoutKey(): void
    {
        PageFactory::createOne([
            'slug' => 'generic-page',
            'path' => '/generic-page',
            'key' => null,
            'status' => Page::STATUS_PUBLISHED,
        ]);

        self::assertNull($this->repo->findOnePublishedByKey(PageKey::About));
    }
}
