<?php

declare(strict_types=1);

namespace App\Notification\Application\Reconciliation;

use App\Notification\Application\Contract\NotificationReconcilerInterface;
use App\Notification\Application\Definition\NotificationRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Re-publishes lost notifications from the producers' business records (ADR 016, "Recovery").
 * The hourly run looks back 24 hours; an explicit catch-up may look back as far as the shortest
 * deduplication period of all registered types, because older keys may already be gone.
 */
final readonly class NotificationReconciliation
{
    public const string WINDOW = 'PT24H';

    /**
     * @param iterable<NotificationReconcilerInterface> $reconcilers
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by the container.
     */
    public function __construct(
        #[AutowireIterator(NotificationReconcilerInterface::TAG)]
        private iterable $reconcilers,
        private NotificationRegistry $registry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int number of outcomes published again
     */
    public function reconcileRecent(): int
    {
        $now = $this->clock->now();

        return $this->reconcile($now->sub(new \DateInterval(self::WINDOW)), $now);
    }

    /**
     * @return int number of outcomes published again
     *
     * @throws \InvalidArgumentException when the range is empty or reaches beyond the deduplication period
     */
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($from >= $to) {
            throw new \InvalidArgumentException('The start of the range must be before its end.');
        }

        $earliest = $this->earliestAllowedStart();
        if ($from < $earliest) {
            throw new \InvalidArgumentException(\sprintf('The range may not start before %s: older deduplication keys may already be deleted.', $earliest->format('Y-m-d H:i:s')));
        }

        $published = 0;
        foreach ($this->reconcilers as $reconciler) {
            $published += $reconciler->reconcile($from, $to);
        }

        return $published;
    }

    public function earliestAllowedStart(): \DateTimeImmutable
    {
        $now = $this->clock->now();
        $earliest = null;

        foreach ($this->registry->definitions() as $definition) {
            $start = $now->sub($definition->deduplicationInterval());
            if (null === $earliest || $start > $earliest) {
                $earliest = $start;
            }
        }

        return $earliest ?? $now->sub(new \DateInterval(self::WINDOW));
    }
}
