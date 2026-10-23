<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Message\DeliverNotificationMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hands pending deliveries to the delivery job again: after a duplicate publication (grace period
 * 5 minutes) and in the hourly resumption (older than 15 minutes, whatever their age). `failed` deliveries are never restarted.
 */
final readonly class NotificationDeliveryQueue
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
    ) {
    }

    public function dispatch(int $deliveryId): void
    {
        $this->bus->dispatch(new DeliverNotificationMessage($deliveryId));
    }

    /**
     * Pending deliveries of the instance a held deduplication key points to, created before $before.
     */
    public function redispatchPendingForKey(Connection $connection, int $recipientId, string $deduplicationKey, \DateTimeImmutable $before): void
    {
        $ids = $connection->fetchFirstColumn(
            <<<'SQL'
                SELECT delivery.id
                FROM notification_delivery delivery
                JOIN notification_deduplication dedup ON dedup.notification_id = delivery.notification_id
                WHERE dedup.recipient_id = :recipient AND dedup.deduplication_key = :key
                  AND delivery.status = 'pending' AND delivery.created_at < :before
                ORDER BY delivery.id
                SQL,
            ['recipient' => $recipientId, 'key' => $deduplicationKey, 'before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        $this->dispatchAll($ids);
    }

    /**
     * Pending deliveries created before $before, oldest first, at most $limit.
     *
     * @return int number of deliveries dispatched again
     */
    public function redispatchPendingCreatedBefore(\DateTimeImmutable $before, int $limit): int
    {
        $ids = $this->connection->fetchFirstColumn(
            \sprintf("SELECT id FROM notification_delivery WHERE status = 'pending' AND created_at < :before ORDER BY created_at, id LIMIT %d", max(1, $limit)),
            ['before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        return $this->dispatchAll($ids);
    }

    /**
     * @param list<mixed> $ids
     */
    private function dispatchAll(array $ids): int
    {
        foreach ($ids as $id) {
            $this->dispatch((int) $id);
        }

        return \count($ids);
    }
}
