<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Inbox;

use App\Notification\Application\Contract\NotificationInboxWriterInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Changes the read and removal state of a recipient's own entries. Every visible change updates
 * version and unread_count of the recipient in the same transaction (ADR 016, "Polling").
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for NotificationInboxWriterInterface.
 */
#[AsAlias(NotificationInboxWriterInterface::class)]
final readonly class DbalNotificationInboxWriter implements NotificationInboxWriterInterface
{
    private const string OWN_VISIBLE = 'recipient_id = :recipient AND in_app_visible = true AND removed_at IS NULL';

    private const string ID_CONDITION = ' AND id = ANY(CAST(:ids AS int[]))';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function markRead(int $recipientId, int $notificationId): bool
    {
        return 1 === $this->markReadMany($recipientId, [$notificationId]);
    }

    #[\Override]
    public function markReadMany(int $recipientId, array $notificationIds): int
    {
        if ([] === $notificationIds) {
            return 0;
        }

        return $this->markReadWhere($recipientId, self::ID_CONDITION, ['ids' => $this->postgresIntArray($notificationIds)]);
    }

    #[\Override]
    public function markAllRead(int $recipientId): int
    {
        return $this->markReadWhere($recipientId, '', []);
    }

    #[\Override]
    public function remove(int $recipientId, int $notificationId): bool
    {
        return 1 === $this->removeMany($recipientId, [$notificationId]);
    }

    #[\Override]
    public function removeMany(int $recipientId, array $notificationIds): int
    {
        if ([] === $notificationIds) {
            return 0;
        }

        return $this->removeWhere($recipientId, self::ID_CONDITION, ['ids' => $this->postgresIntArray($notificationIds)]);
    }

    #[\Override]
    public function removeAll(int $recipientId): int
    {
        return $this->removeWhere($recipientId, '', []);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function markReadWhere(int $recipientId, string $condition, array $parameters): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($recipientId, $condition, $parameters): int {
            $changed = $connection->executeStatement(
                \sprintf('UPDATE notification SET read_at = :now WHERE %s%s AND read_at IS NULL', self::OWN_VISIBLE, $condition),
                ['now' => $this->clock->now(), 'recipient' => $recipientId, ...$parameters],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            if ($changed > 0) {
                $this->bumpState($connection, $recipientId, -$changed);
            }

            return $changed;
        });
    }

    /**
     * @param array<string, string> $parameters
     */
    private function removeWhere(int $recipientId, string $condition, array $parameters): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($recipientId, $condition, $parameters): int {
            $removed = $connection->fetchFirstColumn(
                \sprintf('UPDATE notification SET removed_at = :now WHERE %s%s RETURNING read_at IS NULL', self::OWN_VISIBLE, $condition),
                ['now' => $this->clock->now(), 'recipient' => $recipientId, ...$parameters],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            if ([] === $removed) {
                return 0;
            }

            $wasUnread = \count(array_filter($removed, static fn (mixed $unread): bool => true === $unread));
            $this->bumpState($connection, $recipientId, -$wasUnread);

            return \count($removed);
        });
    }

    /**
     * PostgreSQL array literal for an int[] parameter, e.g. "{3,5,8}".
     *
     * @param list<int> $ids
     */
    private function postgresIntArray(array $ids): string
    {
        return '{'.implode(',', array_unique($ids)).'}';
    }

    private function bumpState(Connection $connection, int $recipientId, int $unreadDelta): void
    {
        $connection->executeStatement(
            <<<'SQL'
                UPDATE notification_recipient_state
                SET version = version + 1,
                    unread_count = GREATEST(unread_count + :delta, 0),
                    updated_at = :now
                WHERE user_id = :recipient
                SQL,
            ['delta' => $unreadDelta, 'now' => $this->clock->now(), 'recipient' => $recipientId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
