<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Contract\NotificationReconcilerInterface;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Announces deliveries that were sent or failed in the range again (ADR 016, "Recovery"; ADR 019). When a producer
 * could not record an outcome in time (process crash, database briefly gone while a mail went out or Messenger gave
 * up a job), it catches up here. Nothing is sent again: only the event is repeated, and producers handle it
 * idempotently.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationReconcilerInterface.
 */
final readonly class DeliveryOutcomeReconciler implements NotificationReconcilerInterface
{
    public function __construct(
        private Connection $connection,
        private NotificationDeliveryAnnouncer $announcer,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, status, reason_code FROM notification_delivery WHERE status IN ('sent', 'failed') AND status_changed_at BETWEEN :from AND :to ORDER BY status_changed_at, id",
            ['from' => $from, 'to' => $to],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        $announced = 0;
        foreach ($rows as $row) {
            $deliveryId = (int) $row['id'];

            try {
                if ('sent' === $row['status']) {
                    $this->announcer->announceSent($deliveryId);
                } else {
                    $reason = NotificationDeliveryFailureReason::tryFrom((string) $row['reason_code']) ?? NotificationDeliveryFailureReason::DeliveryJobFailed;
                    $this->announcer->announceFailed($deliveryId, $reason);
                }
                ++$announced;
            } catch (\Throwable $exception) {
                $this->logger->error('notification.reconciliation.announcement_failed', ['delivery_id' => $deliveryId, 'status' => (string) $row['status'], 'exception' => $exception::class]);
            }
        }

        return $announced;
    }
}
