<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Event\NotificationDeliveryFailed;
use App\Notification\Application\Event\NotificationDeliverySent;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Announces the outcome of a delivery, with the type and parameters of its notification, so producers can
 * complete their own records (NotificationDeliverySent, NotificationDeliveryFailed).
 */
final readonly class NotificationDeliveryAnnouncer
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function announceSent(int $deliveryId): void
    {
        $notification = $this->notification($deliveryId);
        if (null === $notification) {
            return;
        }

        $this->dispatcher->dispatch(new NotificationDeliverySent($deliveryId, $notification['id'], $notification['type'], $notification['parameters']));
    }

    public function announceFailed(int $deliveryId, NotificationDeliveryFailureReason $reason): void
    {
        $notification = $this->notification($deliveryId);
        if (null === $notification) {
            return;
        }

        $this->dispatcher->dispatch(new NotificationDeliveryFailed($deliveryId, $notification['id'], $notification['type'], $notification['parameters'], $reason->value));
    }

    /**
     * @return array{id: int, type: string, parameters: array<string, scalar|null>}|null
     */
    private function notification(int $deliveryId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT n.id, n.type, n.parameters FROM notification_delivery d JOIN notification n ON n.id = d.notification_id WHERE d.id = :id',
            ['id' => $deliveryId],
        );
        if (false === $row) {
            return null;
        }

        $decoded = json_decode((string) $row['parameters'], true);
        $parameters = [];
        foreach (\is_array($decoded) ? $decoded : [] as $key => $value) {
            if (\is_string($key) && (null === $value || \is_scalar($value))) {
                $parameters[$key] = $value;
            }
        }

        return ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'parameters' => $parameters];
    }
}
