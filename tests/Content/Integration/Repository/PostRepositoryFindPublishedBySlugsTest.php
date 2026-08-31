<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Repository;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Content\Infrastructure\Repository\PostRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class PostRepositoryFindPublishedBySlugsTest extends DatabaseKernelTestCase
{
    public function testFindPublishedBySlugsFiltersDraftAndFutureAndEmptyInput(): void
    {
        PostFactory::createOne([
            'slug' => 'published-slug',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('-1 day'),
        ]);
        PostFactory::createOne([
            'slug' => 'draft-slug',
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);
        PostFactory::createOne([
            'slug' => 'future-slug',
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+2 days'),
        ]);

        $repository = self::getContainer()->get(PostRepository::class);

        $found = $repository->findPublishedBySlugs(['published-slug', 'draft-slug', 'future-slug', 'missing-slug']);
        $slugs = array_map(static fn ($post): ?string => $post->getSlug(), $found);

        self::assertSame(['published-slug'], $slugs);
        self::assertSame([], $repository->findPublishedBySlugs([]));
        self::assertSame([], $repository->findPublishedBySlugs(['']));
    }
}
