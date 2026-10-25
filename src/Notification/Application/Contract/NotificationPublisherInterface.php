<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Publishing\NotificationRequest;

/**
 * Cross-context port for publishing notifications (ADR 016).
 *
 * Invalid requests (unknown type, empty deduplication key, missing scope reference) throw.
 * Failures while storing the notification for one recipient are logged and never thrown,
 * so they cannot change the business outcome of the caller.
 */
interface NotificationPublisherInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Port for producing contexts; the first caller arrives with step 3b of ADR 016. */
    public function publish(NotificationRequest $request): void;
}
