<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

/**
 * Badge state of one recipient. A recipient without any notification yet has version 0.
 */
final readonly class RecipientInboxState
{
    public function __construct(
        public int $version = 0,
        public int $unreadCount = 0,
    ) {
    }
}
