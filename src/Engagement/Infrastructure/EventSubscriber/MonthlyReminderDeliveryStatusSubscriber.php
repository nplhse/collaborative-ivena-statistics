<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\EventSubscriber;

use App\Engagement\Application\MonthlyReminderNotificationType;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Notification\Application\Event\NotificationDeliveryFailed;
use App\Notification\Application\Event\NotificationDeliverySent;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Completes a reminder dispatch from the outcome of its notification's delivery (ADR 016, ADR 019): sent when the
 * email went out, failed when it failed for good, so a failed dispatch no longer blocks the next scheduled attempt.
 * Failures before the mailer (owner disabled, delivery job out of retries) arrive the same way.
 *
 * The notification's parameters name the dispatch, its hospital and the send attempt; only a dispatch that is still
 * queued for exactly that attempt changes, so a late outcome of an earlier attempt never touches a newer one. Both
 * updates are idempotent, which lets the reconciliation repeat them.
 *
 * A failure runs synchronously in the transaction that marks the delivery as failed: if this update fails, the
 * delivery stays pending and is retried. A success runs after the delivery is marked as sent; if this update fails,
 * the reconciliation repeats it.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class MonthlyReminderDeliveryStatusSubscriber
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private MonthlyReminderDispatchRepository $dispatchRepository,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener]
    public function onDeliverySent(NotificationDeliverySent $event): void
    {
        $dispatch = $this->dispatchOf($event->type, $event->parameters);
        if (null === $dispatch) {
            return;
        }

        $this->dispatchRepository->markAttemptSent(
            $dispatch['dispatchId'],
            $dispatch['hospitalId'],
            $dispatch['attemptId'],
            $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE)),
        );
    }

    #[AsEventListener]
    public function onDeliveryFailed(NotificationDeliveryFailed $event): void
    {
        $dispatch = $this->dispatchOf($event->type, $event->parameters);
        if (null === $dispatch) {
            return;
        }

        $this->dispatchRepository->markAttemptFailed(
            $dispatch['dispatchId'],
            $dispatch['hospitalId'],
            $dispatch['attemptId'],
            \sprintf('notification_delivery:%s', $event->reasonCode),
        );
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return array{dispatchId: int, hospitalId: int, attemptId: string|null}|null
     */
    private function dispatchOf(string $type, array $parameters): ?array
    {
        $dispatchId = $parameters['dispatchId'] ?? null;
        $hospitalId = $parameters['hospitalId'] ?? null;
        $attemptId = $parameters['attemptId'] ?? null;
        if (MonthlyReminderNotificationType::TYPE !== $type || !\is_int($dispatchId) || !\is_int($hospitalId)
            || (null !== $attemptId && !\is_string($attemptId))) {
            return null;
        }

        return ['dispatchId' => $dispatchId, 'hospitalId' => $hospitalId, 'attemptId' => $attemptId];
    }
}
