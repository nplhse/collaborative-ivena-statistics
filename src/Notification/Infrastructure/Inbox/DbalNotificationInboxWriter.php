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

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the entry changed from unread to read
     */
    #[\Override]
    public function markRead(int $recipientId, int $notificationId): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($recipientId, $notificationId): bool {
            $changed = $connection->executeStatement(
                \sprintf('UPDATE notification SET read_at = :now WHERE %s AND id = :id AND read_at IS NULL', self::OWN_VISIBLE),
                ['now' => $this->clock->now(), 'recipient' => $recipientId, 'id' => $notificationId],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            if (0 === $changed) {
                return false;
            }

            $this->bumpState($connection, $recipientId, -1);

            return true;
        });
    }

    /**
     * @return int number of entries that changed from unread to read
     */
    #[\Override]
    public function markAllRead(int $recipientId): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($recipientId): int {
            $changed = $connection->executeStatement(
                \sprintf('UPDATE notification SET read_at = :now WHERE %s AND read_at IS NULL', self::OWN_VISIBLE),
                ['now' => $this->clock->now(), 'recipient' => $recipientId],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            if ($changed > 0) {
                $this->bumpState($connection, $recipientId, -$changed);
            }

            return $changed;
        });
    }

    /**
     * Hides the entry at once. Outstanding deliveries are not cancelled; the cleanup task deletes the row later.
     *
     * @return bool whether the entry was removed by this call
     */
    #[\Override]
    public function remove(int $recipientId, int $notificationId): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($recipientId, $notificationId): bool {
            $removed = $connection->fetchAssociative(
                \sprintf('UPDATE notification SET removed_at = :now WHERE %s AND id = :id RETURNING read_at', self::OWN_VISIBLE),
                ['now' => $this->clock->now(), 'recipient' => $recipientId, 'id' => $notificationId],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            if (false === $removed) {
                return false;
            }

            $this->bumpState($connection, $recipientId, null === $removed['read_at'] ? -1 : 0);

            return true;
        });
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
