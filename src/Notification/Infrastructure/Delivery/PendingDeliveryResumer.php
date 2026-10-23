<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Delivery;

use App\Notification\Application\Contract\PendingDeliveryResumerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Re-dispatches deliveries that are still `pending` at least 15 minutes after their creation, e.g. because
 * the worker lost the job. Oldest first and at most BATCH_SIZE per run, so older deliveries are never pushed
 * out by newer ones; the next run continues with the rest. The delivery job locks each row and stops unless
 * it is still pending, so a delivery that is processed meanwhile is not sent twice.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for PendingDeliveryResumerInterface.
 */
#[AsAlias(PendingDeliveryResumerInterface::class)]
final readonly class PendingDeliveryResumer implements PendingDeliveryResumerInterface
{
    public const string MINIMUM_AGE = 'PT15M';

    public const int BATCH_SIZE = 500;

    public function __construct(
        private NotificationDeliveryQueue $deliveryQueue,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function resume(): int
    {
        return $this->deliveryQueue->redispatchPendingCreatedBefore(
            $this->clock->now()->sub(new \DateInterval(self::MINIMUM_AGE)),
            self::BATCH_SIZE,
        );
    }
}
