<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageRepositoryQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private PageRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = self::getContainer()->get(PageRepository::class);
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
}
