<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\EventSubscriber\UserRegisteredActivitySubscriber;
use App\User\Infrastructure\Repository\UserActivityRepository;
use App\User\Infrastructure\Repository\UserRepository;

final class UserRegisteredActivitySubscriberTest extends DatabaseKernelTestCase
{
    public function testRegistrationWritesJoinedActivityOnce(): void
    {
        $user = UserFactory::createOne([
            'createdAt' => new \DateTimeImmutable('2022-04-17 08:00:00'),
        ]);
        $userId = $user->getId();
        self::assertNotNull($userId);

        $subscriber = new UserRegisteredActivitySubscriber(
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(UserActivityRecorderInterface::class),
        );
        $subscriber->onUserRegistered(new UserRegistered($userId));
        $subscriber->onUserRegistered(new UserRegistered($userId));

        $rows = self::getContainer()->get(UserActivityRepository::class)->findBy(['user' => $user]);
        self::assertCount(1, $rows);
        self::assertSame(UserActivityType::JOINED, $rows[0]->getType());
        self::assertSame('2022-04-17 08:00:00', $rows[0]->getOccurredAt()->format('Y-m-d H:i:s'));
    }
}
