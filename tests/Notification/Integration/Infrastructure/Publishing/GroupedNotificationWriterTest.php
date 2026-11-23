<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Publishing;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Inbox\NotificationVisibilityResolver;
use App\Notification\Application\Preference\NotificationChannelResolver;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Notification\Infrastructure\Delivery\NotificationDeliveryQueue;
use App\Notification\Infrastructure\Inbox\DbalNotificationInboxWriter;
use App\Notification\Infrastructure\Preference\DbalNotificationPreferenceStore;
use App\Notification\Infrastructure\Publishing\DbalNotificationPublisher;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Messenger\RecordingMessageBus;
use App\Tests\Support\Notification\Type\GroupedTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class GroupedNotificationWriterTest extends DatabaseKernelTestCase
{
    private Connection $connection;
    private MockClock $clock;
    private DbalNotificationPublisher $writer;
    private DbalNotificationPreferenceStore $preferences;
    private DbalNotificationInboxWriter $inbox;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->clock = new MockClock('2026-11-23 12:00:00');
        $this->preferences = new DbalNotificationPreferenceStore($this->connection);
        $this->inbox = new DbalNotificationInboxWriter($this->connection, $this->clock);
        $this->writer = new DbalNotificationPublisher(
            $this->connection,
            new NotificationRegistry([
                'test.grouped' => GroupedTestNotification::class,
                'test.in_app' => InAppTestNotification::class,
            ]),
            new NotificationChannelResolver(),
            $this->preferences,
            $this->clock,
            new BufferingLogger(),
            new NotificationDeliveryQueue($this->connection, new RecordingMessageBus()),
            self::getContainer()->get(NotificationVisibilityResolver::class),
            self::getContainer()->get(UserRepository::class),
        );
    }

    public function testCreateStoresOneUnreadEntryWithoutToastOrDeduplication(): void
    {
        $userId = $this->createUserId();

        $id = $this->create($userId, 3);

        self::assertSame(['count' => 3], $this->parameters($id));
        self::assertNull($this->connection->fetchOne('SELECT toast_pending_until FROM notification WHERE id = ?', [$id]));
        self::assertSame(['version' => 1, 'unread_count' => 1], $this->recipientState($userId));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification_deduplication WHERE recipient_id = ?', [$userId]));
    }

    public function testCreateRespectsThePreference(): void
    {
        $userId = $this->createUserId();
        $this->preferences->save($userId, [
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.grouped', NotificationChannel::InApp, false),
        ]);

        self::assertNull($this->writer->create($this->request($userId, 1)));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$userId]));
    }

    public function testCreateRejectsATypeThatOffersAnotherChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer->create(new NotificationRequest('test.in_app', [$this->createUserId()], 'test.in_app:1'));
    }

    public function testCreateRejectsSeveralRecipients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer->create(new NotificationRequest('test.grouped', [$this->createUserId(), $this->createUserId()], 'test.grouped:1'));
    }

    public function testReplaceUpdatesTheTextWithoutRaisingTheUnreadCount(): void
    {
        $userId = $this->createUserId();
        $id = $this->create($userId, 3);
        $this->clock->sleep(60);

        self::assertTrue($this->writer->replace($id, ['count' => 12]));

        self::assertSame(['count' => 12], $this->parameters($id));
        self::assertSame('2026-11-23 12:00:00', $this->createdAt($id), 'Without a new position the entry stays where it is.');
        self::assertSame(['version' => 2, 'unread_count' => 1], $this->recipientState($userId));
    }

    public function testReplaceCanMoveTheEntryUp(): void
    {
        $id = $this->create($this->createUserId(), 3);

        self::assertTrue($this->writer->replace($id, ['count' => 4], new \DateTimeImmutable('2026-11-23 13:30:00')));

        self::assertSame('2026-11-23 13:30:00', $this->createdAt($id));
    }

    public function testReplaceLeavesAReadEntryAlone(): void
    {
        $userId = $this->createUserId();
        $id = $this->create($userId, 3);
        $this->inbox->markRead($userId, $id);

        self::assertFalse($this->writer->replace($id, ['count' => 12]));

        self::assertSame(['count' => 3], $this->parameters($id));
        self::assertSame(['version' => 2, 'unread_count' => 0], $this->recipientState($userId));
    }

    public function testReplaceLeavesARemovedEntryAlone(): void
    {
        $userId = $this->createUserId();
        $id = $this->create($userId, 3);
        $this->inbox->remove($userId, $id);

        self::assertFalse($this->writer->replace($id, ['count' => 12]));
        self::assertSame(['count' => 3], $this->parameters($id));
    }

    public function testReplaceOfAnUnknownEntryReturnsFalse(): void
    {
        self::assertFalse($this->writer->replace(999_999, ['count' => 1]));
    }

    public function testWithdrawRemovesAnUnreadEntryAndLowersTheCount(): void
    {
        $userId = $this->createUserId();
        $id = $this->create($userId, 1);

        self::assertTrue($this->writer->withdraw($id));

        self::assertSame('2026-11-23 12:00:00', $this->connection->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$id]));
        self::assertSame(['version' => 2, 'unread_count' => 0], $this->recipientState($userId));
    }

    public function testWithdrawLeavesAReadEntryAlone(): void
    {
        $userId = $this->createUserId();
        $id = $this->create($userId, 1);
        $this->inbox->markRead($userId, $id);

        self::assertFalse($this->writer->withdraw($id));

        self::assertNull($this->connection->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$id]));
        self::assertSame(['version' => 2, 'unread_count' => 0], $this->recipientState($userId));
    }

    public function testAWithdrawnGroupCanComeBackWithTheSameKey(): void
    {
        $userId = $this->createUserId();
        $this->writer->withdraw($this->create($userId, 1));

        $this->create($userId, 1);

        self::assertSame(1, $this->recipientState($userId)['unread_count']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$userId]));
    }

    public function testCreateRollsBackWithTheCaller(): void
    {
        $userId = $this->createUserId();

        $this->connection->beginTransaction();
        $this->create($userId, 1);
        $this->connection->rollBack();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$userId]));
    }

    private function create(int $userId, int $count): int
    {
        $id = $this->writer->create($this->request($userId, $count));
        self::assertNotNull($id);

        return $id;
    }

    private function request(int $userId, int $count): NotificationRequest
    {
        return new NotificationRequest('test.grouped', [$userId], 'test.grouped:target', ['count' => $count]);
    }

    private function createUserId(): int
    {
        $id = UserFactory::createOne()->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(int $id): array
    {
        $parameters = json_decode((string) $this->connection->fetchOne('SELECT parameters FROM notification WHERE id = ?', [$id]), true);
        self::assertIsArray($parameters);

        return $parameters;
    }

    private function createdAt(int $id): string
    {
        return (string) $this->connection->fetchOne('SELECT created_at FROM notification WHERE id = ?', [$id]);
    }

    /**
     * @return array{version: int, unread_count: int}
     */
    private function recipientState(int $userId): array
    {
        $row = $this->connection->fetchAssociative('SELECT version, unread_count FROM notification_recipient_state WHERE user_id = ?', [$userId]);
        self::assertIsArray($row);

        return ['version' => (int) $row['version'], 'unread_count' => (int) $row['unread_count']];
    }
}
