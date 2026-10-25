<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

/**
 * A delivery (e.g. an email) failed for good: no retry follows (ADR 016, "Delivery"). Dispatched after the
 * status change, so producers can update their own records, e.g. Engagement its reminder dispatch, without
 * Notification knowing them. The notification's stored type and parameters identify the business record.
 *
 * @psalm-suppress PossiblyUnusedProperty deliveryId and notificationId identify the failure for any producer and for logs.
 */
final readonly class NotificationDeliveryFailed
{
    /**
     * @param array<string, scalar|null> $parameters the notification's stored parameters
     */
    public function __construct(
        public int $deliveryId,
        public int $notificationId,
        public string $type,
        public array $parameters,
        public string $reasonCode,
    ) {
    }
}
