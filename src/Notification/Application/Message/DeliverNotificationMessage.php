<?php

declare(strict_types=1);

namespace App\Notification\Application\Message;

/**
 * Hands one stored delivery (notification_delivery) to its channel (ADR 016, "Delivery").
 */
final readonly class DeliverNotificationMessage
{
    public function __construct(
        public int $deliveryId,
    ) {
    }
}
