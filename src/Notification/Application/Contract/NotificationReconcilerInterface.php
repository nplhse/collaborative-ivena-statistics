<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by producing contexts that can rebuild their notifications from durable business
 * records (ADR 016, "Recovery"). Publishing again is safe: deduplication turns known outcomes into no-ops.
 */
#[AutoconfigureTag(self::TAG)]
interface NotificationReconcilerInterface
{
    public const string TAG = 'app.notification.reconciler';

    /**
     * Re-publishes the notifications of all outcomes reached in [$from, $to].
     *
     * @return int number of outcomes that were published again
     */
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int;
}
