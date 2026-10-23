<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Contract\NotificationReconcilerInterface;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Announces deliveries that failed in the range again (ADR 016, "Recovery"). When a producer could not
 * record a failure in time (process crash, database briefly gone while Messenger gave up a job), it catches
 * up here. Nothing is sent again: only the event is repeated, and producers handle it idempotently.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationReconcilerInterface.
 */
final readonly class FailedDeliveryReconciler implements NotificationReconcilerInterface
{
    public function __construct(
        private Connection $connection,
        private NotificationDeliveryFailureAnnouncer $failureAnnouncer,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, reason_code FROM notification_delivery WHERE status = 'failed' AND status_changed_at BETWEEN :from AND :to ORDER BY status_changed_at, id",
            ['from' => $from, 'to' => $to],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        $announced = 0;
        foreach ($rows as $row) {
            $reason = NotificationDeliveryFailureReason::tryFrom((string) $row['reason_code']) ?? NotificationDeliveryFailureReason::DeliveryJobFailed;

            try {
                $this->failureAnnouncer->announce((int) $row['id'], $reason);
                ++$announced;
            } catch (\Throwable $exception) {
                $this->logger->error('notification.reconciliation.failure_announcement_failed', ['delivery_id' => (int) $row['id'], 'exception' => $exception::class]);
            }
        }

        return $announced;
    }
}
