<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Retention\NotificationRetentionResult;

interface NotificationRetentionInterface
{
    /**
     * Deletes expired inbox entries and deduplication keys and recounts unread entries per recipient.
     */
    public function run(): NotificationRetentionResult;
}
