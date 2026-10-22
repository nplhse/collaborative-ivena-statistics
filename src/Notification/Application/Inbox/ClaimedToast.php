<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

use App\Notification\Domain\Enum\NotificationPriority;

/**
 * An inbox entry whose toast was just handed to a browser context (ADR 016, "Toast delivery").
 */
final readonly class ClaimedToast
{
    public function __construct(
        public InboxEntry $entry,
        public NotificationPriority $priority,
    ) {
    }
}
