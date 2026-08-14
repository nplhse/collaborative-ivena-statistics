<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Adapter;

use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Adapter\DoctrineUserPublishedCommentsProvider;
use App\Content\Infrastructure\Factory\PostCommentFactory;
use App\Content\Infrastructure\Factory\PostFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class DoctrineUserPublishedCommentsProviderTest extends DatabaseKernelTestCase
{
    public function testCountExcludesCommentsOnDraftPosts(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        PostCommentFactory::createOne([
            'author' => $user,
            'createdBy' => $user,
            'post' => PostFactory::createOne([
                'createdBy' => $user,
                'status' => PostStatus::PUBLISHED,
                'publishedAt' => new \DateTimeImmutable('-1 day'),
            ]),
        ]);
        PostCommentFactory::createOne([
            'author' => $user,
            'createdBy' => $user,
            'post' => PostFactory::createOne([
                'createdBy' => $user,
                'status' => PostStatus::DRAFT,
                'publishedAt' => null,
            ]),
        ]);

        $provider = self::getContainer()->get(DoctrineUserPublishedCommentsProvider::class);

        self::assertSame(1, $provider->countOnPublishedPostsByUserId($userId));
    }
}
