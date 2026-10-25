<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Retention;

use App\Notification\Domain\Entity\NotificationDeduplication;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Notification\Infrastructure\Retention\DbalNotificationRetention;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class DbalNotificationRetentionTest extends DatabaseKernelTestCase
{
    private const string NOW = '2026-10-22 03:00:00';

    public function testDeletesExpiredEntriesAndKeys(): void
    {
        $connection = $this->connection();
        $state = new NotificationTestState($connection);
        $user = UserFactory::createOne();

        $readOld = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-08-01 10:00:00')]);
        $state->markRead($readOld, new \DateTimeImmutable('2026-09-21 02:59:00'));
        $readRecent = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-08-01 10:00:00')]);
        $state->markRead($readRecent, new \DateTimeImmutable('2026-09-23 10:00:00'));
        $unreadOld = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-07-23 02:00:00')]);
        $unreadRecent = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-07-24 10:00:00')]);
        $removed = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-10-21 10:00:00')]);
        $state->markRemoved($removed, new \DateTimeImmutable('2026-10-21 11:00:00'));
        $emailOnly = NotificationFactory::createOne(['recipient' => $user, 'inAppVisible' => false, 'createdAt' => new \DateTimeImmutable('2026-01-01 10:00:00')]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new NotificationDeduplication($user, 'expired', new \DateTimeImmutable('2026-10-22 02:00:00')));
        $entityManager->persist(new NotificationDeduplication($user, 'valid', new \DateTimeImmutable('2026-10-23 02:00:00')));
        $entityManager->flush();

        $result = $this->retention()->run();

        $remaining = array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM notification ORDER BY id'));
        sort($remaining);
        $expected = array_map(static fn (\App\Notification\Domain\Entity\Notification $n): int => (int) $n->getId(), [$readRecent, $unreadRecent]);
        sort($expected);
        self::assertSame($expected, $remaining);
        self::assertSame(4, $result->deletedNotifications, 'an email-only instance without open deliveries goes too');
        self::assertNotContains($emailOnly->getId(), $remaining);
        self::assertSame(1, $result->deletedDeduplicationKeys);
        self::assertSame(['valid'], $connection->fetchFirstColumn('SELECT deduplication_key FROM notification_deduplication'));
        self::assertNotContains($unreadOld->getId(), $remaining);
    }

    public function testInstancesStayUntilTheirDeliveriesAreDoneForThirtyDays(): void
    {
        $connection = $this->connection();
        $state = new NotificationTestState($connection);
        $created = new \DateTimeImmutable('2026-08-01 10:00:00');

        $removedWithPendingMail = NotificationFactory::createOne(['createdAt' => $created]);
        $state->markRemoved($removedWithPendingMail, $created);
        $this->delivery($removedWithPendingMail->getId(), 'pending', '2026-08-01 10:00:00');
        $mailSentRecently = NotificationFactory::createOne(['inAppVisible' => false, 'createdAt' => $created]);
        $this->delivery($mailSentRecently->getId(), 'sent', '2026-10-01 10:00:00');
        $mailFailedLongAgo = NotificationFactory::createOne(['inAppVisible' => false, 'createdAt' => $created]);
        $this->delivery($mailFailedLongAgo->getId(), 'failed', '2026-09-01 10:00:00');

        $result = $this->retention()->run();

        self::assertSame(1, $result->deletedNotifications);
        self::assertSame(
            [$removedWithPendingMail->getId(), $mailSentRecently->getId()],
            array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM notification ORDER BY id')),
            'removing an entry never cancels its delivery',
        );
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_delivery'), 'deliveries go with their instance');
    }

    public function testRecountsWithInboxVisibilityRules(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN']]);
        $participant = UserFactory::createOne(['roles' => ['ROLE_PARTICIPANT']]);
        $untouched = UserFactory::createOne();
        $createdAt = new \DateTimeImmutable('2026-10-21 10:00:00');

        foreach ([$admin, $participant] as $user) {
            NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $createdAt]);
            NotificationFactory::createOne(['recipient' => $user, 'accessScope' => NotificationAccessScope::Hospital, 'scopeReferenceId' => 99, 'createdAt' => $createdAt]);
            NotificationFactory::createOne(['recipient' => $user, 'accessScope' => NotificationAccessScope::Role, 'requiredRole' => 'ROLE_ADMIN', 'createdAt' => $createdAt]);
            NotificationFactory::createOne(['recipient' => $user, 'accessScope' => NotificationAccessScope::Role, 'requiredRole' => 'ROLE_PARTICIPANT', 'createdAt' => $createdAt]);
        }
        NotificationRecipientStateFactory::createOne(['user' => $admin, 'version' => 5, 'unreadCount' => 0]);
        NotificationRecipientStateFactory::createOne(['user' => $participant, 'version' => 5, 'unreadCount' => 9]);
        NotificationRecipientStateFactory::createOne(['user' => $untouched, 'version' => 2, 'unreadCount' => 0]);

        $result = $this->retention()->run();

        self::assertSame(2, $result->correctedRecipients);
        self::assertSame(['version' => 6, 'unread' => 4], $this->state((int) $admin->getId()), 'admin reaches ROLE_PARTICIPANT through the hierarchy');
        self::assertSame(['version' => 6, 'unread' => 3], $this->state((int) $participant->getId()));
        self::assertSame(['version' => 2, 'unread' => 0], $this->state((int) $untouched->getId()));
        self::assertSame(0, $this->retention()->run()->correctedRecipients, 'a second run finds nothing to correct');
    }

    private function delivery(?int $notificationId, string $status, string $statusChangedAt): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO notification_delivery (notification_id, channel, status, attempt_count, created_at, status_changed_at) VALUES (?, 'email', ?, 1, ?, ?)",
            [$notificationId, $status, $statusChangedAt, $statusChangedAt],
        );
    }

    private function retention(): DbalNotificationRetention
    {
        return new DbalNotificationRetention(
            $this->connection(),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(RoleHierarchyInterface::class),
            new MockClock(self::NOW),
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * @return array{version: int, unread: int}
     */
    private function state(int $userId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT version, unread_count FROM notification_recipient_state WHERE user_id = ?', [$userId]);
        self::assertIsArray($row);

        return ['version' => (int) $row['version'], 'unread' => (int) $row['unread_count']];
    }
}
