<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Moves deliveries to `sent` when the mail transport accepted the email, and to `failed` when the
 * email or the delivery job itself is given up (no retry follows). Modelled on
 * MonthlyReminderDeliverySubscriber; stores reason codes, never raw errors.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class NotificationDeliveryStatusSubscriber
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private NotificationDeliveryFailureAnnouncer $failureAnnouncer,
    ) {
    }

    #[AsEventListener(event: SentMessageEvent::class)]
    public function onSent(SentMessageEvent $event): void
    {
        $deliveryId = $this->deliveryId($event->getMessage()->getOriginalMessage());
        if (null === $deliveryId) {
            return;
        }

        $this->connection->executeStatement(
            "UPDATE notification_delivery SET status = 'sent', status_changed_at = :now WHERE id = :id AND status IN ('pending', 'queued')",
            ['now' => $this->clock->now(), 'id' => $deliveryId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        [$deliveryId, $reason] = match (true) {
            $message instanceof SendEmailMessage => [$this->deliveryId($message->getMessage()), NotificationDeliveryFailureReason::TransportFailed],
            $message instanceof DeliverNotificationMessage => [$message->deliveryId, NotificationDeliveryFailureReason::DeliveryJobFailed],
            default => [null, null],
        };

        if (null === $deliveryId || null === $reason) {
            return;
        }

        try {
            // Status and announcement commit together, so producers record the failure with it.
            $this->connection->transactional(function (Connection $connection) use ($deliveryId, $reason): void {
                if ($this->markFailed($connection, $deliveryId, $reason) > 0) {
                    $this->failureAnnouncer->announce($deliveryId, $reason);
                }
            });
        } catch (\Throwable $exception) {
            // Messenger does not retry this job any more. Keep the delivery's final state; the hourly
            // reconciliation announces failed deliveries again, so the producer catches up later.
            $this->logger->error('notification.delivery.failure_announcement_failed', ['delivery_id' => $deliveryId, 'exception' => $exception::class]);
            $this->markFailed($this->connection, $deliveryId, $reason);
        }
    }

    private function markFailed(Connection $connection, int $deliveryId, NotificationDeliveryFailureReason $reason): int
    {
        $updated = $connection->executeStatement(
            "UPDATE notification_delivery SET status = 'failed', reason_code = :reason, status_changed_at = :now WHERE id = :id AND status IN ('pending', 'queued')",
            ['reason' => $reason->value, 'now' => $this->clock->now(), 'id' => $deliveryId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        if ($updated > 0) {
            $this->logger->warning('notification.delivery.failed', ['delivery_id' => $deliveryId, 'reason' => $reason->value]);
        }

        return $updated;
    }

    private function deliveryId(RawMessage $message): ?int
    {
        if (!$message instanceof Message) {
            return null;
        }

        $value = trim((string) $message->getHeaders()->get(NotificationEmailFactory::DELIVERY_ID_HEADER)?->getBodyAsString());

        return '' !== $value && ctype_digit($value) ? (int) $value : null;
    }
}
