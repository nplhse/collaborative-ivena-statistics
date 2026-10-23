<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Contract\NotificationReconcilerInterface;
use Psr\Clock\ClockInterface;

/**
 * Re-dispatches deliveries that are still `pending` 15 minutes after their creation (ADR 016,
 * "Recovery"), e.g. because the worker lost the job. `failed` deliveries are never restarted.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationReconcilerInterface.
 */
final readonly class PendingDeliveryReconciler implements NotificationReconcilerInterface
{
    public const string MINIMUM_AGE = 'PT15M';

    public function __construct(
        private NotificationDeliveryQueue $deliveryQueue,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $before = min($to, $this->clock->now()->sub(new \DateInterval(self::MINIMUM_AGE)));
        if ($before <= $from) {
            return 0;
        }

        return $this->deliveryQueue->redispatchPendingCreatedBetween($from, $before);
    }
}
