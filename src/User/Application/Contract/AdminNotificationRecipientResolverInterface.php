<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

/**
 * Resolves the recipients of admin notifications (ADR 016, "Admin notifications"): every enabled,
 * verified administrator. Each admin chooses on the notification settings page which of these
 * notifications they want and on which channels.
 */
interface AdminNotificationRecipientResolverInterface
{
    /**
     * @return list<int>
     */
    public function recipientIds(): array;
}
