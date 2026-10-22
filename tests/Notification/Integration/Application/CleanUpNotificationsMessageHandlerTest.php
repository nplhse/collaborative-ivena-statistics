<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Application;

use App\Notification\Application\Contract\NotificationRetentionInterface;
use App\Notification\Application\Message\CleanUpNotificationsMessage;
use App\Notification\Application\MessageHandler\CleanUpNotificationsMessageHandler;
use App\Notification\Application\Retention\NotificationRetentionResult;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use Doctrine\DBAL\Connection;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class CleanUpNotificationsMessageHandlerTest extends DatabaseKernelTestCase
{
    public function testScheduledMessageRunsTheRetention(): void
    {
        $removed = NotificationFactory::createOne();
        new NotificationTestState(self::getContainer()->get(Connection::class))->markRemoved($removed, new \DateTimeImmutable('-1 hour'));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CleanUpNotificationsMessage());

        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM notification'));
    }

    public function testSkipsWhileAnotherRunHoldsTheLock(): void
    {
        $retention = new class implements NotificationRetentionInterface {
            public int $runs = 0;

            public function run(): NotificationRetentionResult
            {
                ++$this->runs;

                return new NotificationRetentionResult(0, 0, 0);
            }
        };
        $lockFactory = new LockFactory(new InMemoryStore());
        $logger = new BufferingLogger();
        $handler = new CleanUpNotificationsMessageHandler($retention, $lockFactory, $logger);

        $held = $lockFactory->createLock('notification-retention');
        $held->acquire();
        $handler(new CleanUpNotificationsMessage());
        $held->release();
        $handler(new CleanUpNotificationsMessage());

        self::assertSame(1, $retention->runs);
        $messages = array_column($logger->cleanLogs(), 1);
        self::assertSame(['notification.retention.skipped', 'notification.retention.finished'], $messages);
    }
}
