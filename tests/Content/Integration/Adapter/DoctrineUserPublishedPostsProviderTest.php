<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Adapter;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Adapter\DoctrineUserPublishedPostsProvider;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class DoctrineUserPublishedPostsProviderTest extends DatabaseKernelTestCase
{
    public function testCountPublishedExcludesDraftsAndFuturePosts(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        PostFactory::createOne([
            'createdBy' => $user,
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('2026-07-12 10:00:00'),
        ]);
        PostFactory::createOne([
            'createdBy' => $user,
            'status' => PostStatus::DRAFT,
            'publishedAt' => null,
        ]);
        PostFactory::createOne([
            'createdBy' => $user,
            'status' => PostStatus::PUBLISHED,
            'publishedAt' => new \DateTimeImmutable('+2 days'),
        ]);

        $provider = self::getContainer()->get(DoctrineUserPublishedPostsProvider::class);

        self::assertSame(1, $provider->countPublishedByUserId($userId));
    }
}
