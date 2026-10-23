<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Event\NotificationDeliveryFailed;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Announces a delivery that just failed for good, with the type and parameters of its notification.
 */
final readonly class NotificationDeliveryFailureAnnouncer
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function announce(int $deliveryId, NotificationDeliveryFailureReason $reason): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT n.id, n.type, n.parameters FROM notification_delivery d JOIN notification n ON n.id = d.notification_id WHERE d.id = :id',
            ['id' => $deliveryId],
        );
        if (false === $row) {
            return;
        }

        $decoded = json_decode((string) $row['parameters'], true);
        $parameters = [];
        foreach (\is_array($decoded) ? $decoded : [] as $key => $value) {
            if (\is_string($key) && (null === $value || \is_scalar($value))) {
                $parameters[$key] = $value;
            }
        }

        $this->dispatcher->dispatch(new NotificationDeliveryFailed($deliveryId, (int) $row['id'], (string) $row['type'], $parameters, $reason->value));
    }
}
