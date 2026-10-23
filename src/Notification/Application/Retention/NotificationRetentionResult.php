<?php

declare(strict_types=1);

namespace App\Notification\Application\Retention;

final readonly class NotificationRetentionResult
{
    public function __construct(
        public int $deletedNotifications,
        public int $deletedDeduplicationKeys,
        public int $correctedRecipients,
    ) {
    }
}
