<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Message\DeliverNotificationMessage;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use App\Notification\Domain\Enum\NotificationDeliveryStatus;
use App\Notification\Infrastructure\Query\InboxEntryRowMapper;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

/**
 * Processes one delivery, serialized by a row lock (ADR 016, "Delivery"):
 *
 * 1. lock the delivery (`SELECT … FOR UPDATE`) in a transaction,
 * 2. stop unless it is still `pending`,
 * 3. call Notifier with exactly that channel; for email this stores a SendEmailMessage on the same connection,
 * 4. set `queued` in the same transaction; the lock is released with the commit.
 *
 * Mail events then move the delivery to `sent` or `failed` (NotificationDeliveryStatusSubscriber).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class DeliverNotificationMessageHandler
{
    public function __construct(
        private Connection $connection,
        private NotificationRegistry $registry,
        private NotificationEmailFactory $emailFactory,
        private NotifierInterface $notifier,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private NotificationDeliveryFailureAnnouncer $failureAnnouncer,
    ) {
    }

    public function __invoke(DeliverNotificationMessage $message): void
    {
        $failure = $this->connection->transactional(function (Connection $connection) use ($message): ?NotificationDeliveryFailureReason {
            $columns = implode(', ', array_map(
                static fn (string $column): string => 'n.'.trim($column),
                explode(',', InboxEntryRowMapper::COLUMNS),
            ));

            $row = $connection->fetchAssociative(
                \sprintf(
                    'SELECT d.status AS delivery_status, d.channel AS delivery_channel, n.type, n.recipient_id, %s
                     FROM notification_delivery d JOIN notification n ON n.id = d.notification_id
                     WHERE d.id = :id
                     FOR UPDATE OF d',
                    $columns,
                ),
                ['id' => $message->deliveryId],
            );

            if (false === $row || NotificationDeliveryStatus::Pending->value !== $row['delivery_status']) {
                return null;
            }

            $connection->executeStatement('UPDATE notification_delivery SET attempt_count = attempt_count + 1 WHERE id = :id', ['id' => $message->deliveryId]);

            $recipient = $this->userRepository->find((int) $row['recipient_id']);
            $address = $recipient instanceof User ? trim((string) $recipient->getEmail()) : '';
            if (!$recipient instanceof User || !$recipient->isEnabled() || '' === $address
                || NotificationChannel::Email->value !== $row['delivery_channel']) {
                $this->fail($connection, $message->deliveryId, NotificationDeliveryFailureReason::RecipientUnavailable);

                return NotificationDeliveryFailureReason::RecipientUnavailable;
            }

            $type = (string) $row['type'];
            $email = $this->emailFactory->create($message->deliveryId, $type, InboxEntryRowMapper::map($row), $recipient);
            $class = $this->registry->className($type);

            $this->notifier->send(new $class($email->getSubject() ?? '', [NotificationChannel::Email->value])->withEmail($email), new Recipient($address));

            // Only from pending: with a synchronous mail transport the mail may already be marked as sent.
            $connection->executeStatement(
                "UPDATE notification_delivery SET status = 'queued', status_changed_at = :now WHERE id = :id AND status = 'pending'",
                ['now' => $this->clock->now(), 'id' => $message->deliveryId],
                ['now' => Types::DATETIME_IMMUTABLE],
            );

            return null;
        });

        if ($failure instanceof NotificationDeliveryFailureReason) {
            $this->failureAnnouncer->announce($message->deliveryId, $failure);
        }
    }

    private function fail(Connection $connection, int $deliveryId, NotificationDeliveryFailureReason $reason): void
    {
        $connection->executeStatement(
            "UPDATE notification_delivery SET status = 'failed', reason_code = :reason, status_changed_at = :now WHERE id = :id",
            ['reason' => $reason->value, 'now' => $this->clock->now(), 'id' => $deliveryId],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        $this->logger->warning('notification.delivery.failed', ['delivery_id' => $deliveryId, 'reason' => $reason->value]);
    }
}
