<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Inbox;

use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Notification\Infrastructure\Inbox\DbalNotificationInboxWriter;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;

final class DbalNotificationInboxWriterTest extends DatabaseKernelTestCase
{
    private Connection $connection;
    private DbalNotificationInboxWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->writer = new DbalNotificationInboxWriter($this->connection, new MockClock('2026-10-22 15:00:00'));
    }

    public function testMarkReadUpdatesEntryAndStateOnce(): void
    {
        $user = $this->userWithState(unread: 2);
        $notification = NotificationFactory::createOne(['recipient' => $user]);
        $id = (int) $notification->getId();

        self::assertTrue($this->writer->markRead($this->id($user), $id));
        self::assertFalse($this->writer->markRead($this->id($user), $id), 'a second call changes nothing');

        self::assertSame('2026-10-22 15:00:00', $this->connection->fetchOne('SELECT read_at FROM notification WHERE id = ?', [$id]));
        self::assertSame(['version' => 2, 'unread' => 1], $this->state($user));
    }

    public function testMarkReadIgnoresEntriesOfOtherUsersAndRemovedEntries(): void
    {
        $user = $this->userWithState(unread: 1);
        $foreign = NotificationFactory::createOne();
        $removed = NotificationFactory::createOne(['recipient' => $user]);
        new NotificationTestState($this->connection)->markRemoved($removed, new \DateTimeImmutable('2026-10-22 14:00:00'));

        self::assertFalse($this->writer->markRead($this->id($user), (int) $foreign->getId()));
        self::assertFalse($this->writer->markRead($this->id($user), (int) $removed->getId()));
        self::assertSame(['version' => 1, 'unread' => 1], $this->state($user));
    }

    public function testMarkAllReadChangesOnlyOwnUnreadEntries(): void
    {
        $user = $this->userWithState(unread: 3);
        NotificationFactory::createMany(3, ['recipient' => $user]);
        $foreign = NotificationFactory::createOne();

        self::assertSame(3, $this->writer->markAllRead($this->id($user)));
        self::assertSame(0, $this->writer->markAllRead($this->id($user)));

        self::assertSame(['version' => 2, 'unread' => 0], $this->state($user));
        self::assertNull($this->connection->fetchOne('SELECT read_at FROM notification WHERE id = ?', [$foreign->getId()]));
    }

    public function testRemoveHidesEntryAndAdjustsUnreadCount(): void
    {
        $user = $this->userWithState(unread: 1);
        $unread = NotificationFactory::createOne(['recipient' => $user]);
        $read = NotificationFactory::createOne(['recipient' => $user]);
        new NotificationTestState($this->connection)->markRead($read, new \DateTimeImmutable('2026-10-22 10:00:00'));

        self::assertTrue($this->writer->remove($this->id($user), (int) $read->getId()));
        self::assertSame(['version' => 2, 'unread' => 1], $this->state($user), 'removing a read entry keeps the counter');

        self::assertTrue($this->writer->remove($this->id($user), (int) $unread->getId()));
        self::assertSame(['version' => 3, 'unread' => 0], $this->state($user));

        self::assertFalse($this->writer->remove($this->id($user), (int) $unread->getId()), 'already removed');
        self::assertFalse($this->writer->remove($this->id($user), (int) NotificationFactory::createOne()->getId()), 'foreign entry');
        self::assertSame('2026-10-22 15:00:00', $this->connection->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$unread->getId()]));
    }

    public function testCounterNeverDropsBelowZero(): void
    {
        $user = $this->userWithState(unread: 0);
        $notification = NotificationFactory::createOne(['recipient' => $user]);

        $this->writer->markRead($this->id($user), (int) $notification->getId());

        self::assertSame(['version' => 2, 'unread' => 0], $this->state($user));
    }

    private function userWithState(int $unread): User
    {
        $user = UserFactory::createOne();
        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 1, 'unreadCount' => $unread]);

        return $user;
    }

    private function id(User $user): int
    {
        return (int) $user->getId();
    }

    /**
     * @return array{version: int, unread: int}
     */
    private function state(User $user): array
    {
        $row = $this->connection->fetchAssociative('SELECT version, unread_count FROM notification_recipient_state WHERE user_id = ?', [$user->getId()]);
        self::assertIsArray($row);

        return ['version' => (int) $row['version'], 'unread' => (int) $row['unread_count']];
    }
}
