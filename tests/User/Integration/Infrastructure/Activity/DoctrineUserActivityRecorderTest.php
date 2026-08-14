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
}
