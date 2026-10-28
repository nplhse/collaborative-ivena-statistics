<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Notification;

use App\DataFixtures\Generator\Application\Run\GenerationRunNotifierInterface;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use App\Notification\Application\Contract\NotificationReconcilerInterface;

/**
 * Re-publishes `generation.completed` for runs that finished in the reconciliation window (ADR 016,
 * "Recovery"), in case the process died between the terminal transition and publishing.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationReconcilerInterface.
 */
final readonly class GenerationRunNotificationReconciler implements NotificationReconcilerInterface
{
    public function __construct(
        private GenerationRunStore $store,
        private GenerationRunNotifierInterface $notifier,
    ) {
    }

    #[\Override]
    public function reconcile(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $published = 0;
        foreach ($this->store->notifyingFinishedBetween($from, $to) as $runId) {
            $run = $this->store->find($runId);
            if ($run instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunView) {
                $published += $this->notifier->notify($run);
            }
        }

        return $published;
    }
}
