<?php

declare(strict_types=1);

namespace App\Notification\Application\Snapshot;

use App\Notification\Application\Inbox\ClaimedToast;

/**
 * Badge state of one recipient and, for polls, the toasts claimed with it.
 */
final readonly class NotificationSnapshot
{
    /**
     * @param list<ClaimedToast> $toasts
     */
    public function __construct(
        public int $version,
        public int $unreadCount,
        public array $toasts = [],
    ) {
    }

    /**
     * True when a client that knows $knownVersion has nothing to update: same badge, no toasts.
     */
    public function isUnchangedSince(?int $knownVersion): bool
    {
        return $knownVersion === $this->version && [] === $this->toasts;
    }
}
