<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

/**
 * A delivery (e.g. an email) went out: the mail transport accepted it (ADR 016, "Delivery"; ADR 019). Dispatched
 * after the status change, like NotificationDeliveryFailed, so producers can complete their own records, e.g.
 * Engagement its reminder dispatch, without Notification knowing them. The hourly reconciliation announces it
 * again, so listeners must handle it idempotently.
 *
 * @psalm-suppress PossiblyUnusedProperty deliveryId and notificationId identify the delivery for any producer and for logs.
 */
final readonly class NotificationDeliverySent
{
    /**
     * @param array<string, scalar|null> $parameters the notification's stored parameters
     */
    public function __construct(
        public int $deliveryId,
        public int $notificationId,
        public string $type,
        public array $parameters,
    ) {
    }
}
