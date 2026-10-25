<?php

declare(strict_types=1);

namespace App\Notification\Application\Snapshot;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Contract\NotificationToastClaimerInterface;
use Psr\Clock\ClockInterface;

/**
 * The one fetching logic behind the initial page render and the polling endpoint
 * (ADR 016, "Polling and initial rendering").
 *
 * The page render never claims toasts: the server cannot know whether the tab is visible and in use.
 * Toasts are checked independently of the version, so an equal version never hides a pending toast.
 */
final readonly class NotificationSnapshotProvider
{
    /** Upper bound of toasts claimed by one poll. */
    public const int TOAST_CLAIM_LIMIT = 10;

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationInboxQueryInterface $inboxQuery,
        private NotificationToastClaimerInterface $toastClaimer,
        private ClockInterface $clock,
    ) {
    }

    public function snapshot(int $recipientId, bool $claimToasts = false): NotificationSnapshot
    {
        $toasts = $claimToasts ? $this->toastClaimer->claim($recipientId, $this->clock->now(), self::TOAST_CLAIM_LIMIT) : [];
        $state = $this->inboxQuery->state($recipientId);

        return new NotificationSnapshot($state->version, $state->unreadCount, $toasts);
    }
}
