<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Activity;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserActivityRepository;

final class DoctrineUserActivityRecorderTest extends DatabaseKernelTestCase
{
    public function testSecondInsertWithSameKeyIsSkipped(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $recorder = self::getContainer()->get(UserActivityRecorderInterface::class);
        $write = new UserActivityWrite(
            userId: $userId,
            type: UserActivityType::JOINED,
            occurredAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
            deduplicationKey: UserActivityDeduplicationKey::joined($userId),
        );

        self::assertTrue($recorder->record($write));
        self::assertFalse($recorder->record($write));

        $repository = self::getContainer()->get(UserActivityRepository::class);
        $rows = $repository->findBy(['user' => $user]);
        self::assertCount(1, $rows);
        self::assertSame(UserActivityType::JOINED, $rows[0]->getType());
    }

    public function testSyncUpdatesOccurredAtAndMetadataWithoutDuplicate(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $recorder = self::getContainer()->get(UserActivityRecorderInterface::class);
        $key = UserActivityDeduplicationKey::postPublished($userId, 19);
        $first = new UserActivityWrite(
            userId: $userId,
            type: UserActivityType::POST_PUBLISHED,
            occurredAt: new \DateTimeImmutable('2026-09-03 10:42:00'),
            deduplicationKey: $key,
            metadata: ['postId' => 19, 'title' => 'Alt', 'slug' => 'alt'],
        );
        $updated = new UserActivityWrite(
            userId: $userId,
            type: UserActivityType::POST_PUBLISHED,
            occurredAt: new \DateTimeImmutable('2026-09-02 11:00:00'),
            deduplicationKey: $key,
            metadata: ['postId' => 19, 'title' => 'Neu', 'slug' => 'neu'],
        );

        self::assertTrue($recorder->sync($first));

        $repository = self::getContainer()->get(UserActivityRepository::class);
        $inserted = $repository->findBy(['user' => $user]);
        self::assertCount(1, $inserted);
        $createdAt = $inserted[0]->getCreatedAt();

        self::assertTrue($recorder->sync($updated));

        $rows = $repository->findBy(['user' => $user]);
        self::assertCount(1, $rows);
        self::assertEquals(new \DateTimeImmutable('2026-09-02 11:00:00'), $rows[0]->getOccurredAt());
        self::assertSame('Neu', $rows[0]->getMetadata()['title'] ?? null);
        self::assertSame('neu', $rows[0]->getMetadata()['slug'] ?? null);
        self::assertEquals($createdAt, $rows[0]->getCreatedAt());
    }
}
