<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

/**
 * Hands stored deliveries that are still `pending` to the delivery job again (ADR 016, "Recovery").
 * Independent of the reconciliation window: a pending delivery is resumed whatever its age; only
 * `pending` ones are touched, and no new notification is created.
 */
interface PendingDeliveryResumerInterface
{
    /**
     * @return int number of deliveries dispatched again
     */
    public function resume(): int;
}
