<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

/**
 * Resolves the recipients of admin notifications from roles (ADR 016, "Admin notifications").
 * Only enabled, verified administrators are returned; each gets their own notification.
 */
interface AdminNotificationRecipientResolverInterface
{
    /**
     * Administrators who receive system notifications (ROLE_ADMIN + ROLE_RECEIVES_NOTIFICATION).
     *
     * @return list<int>
     */
    public function systemRecipientIds(): array;

    /**
     * Administrators who receive feedback (ROLE_ADMIN + ROLE_FEEDBACK_RECIPIENT).
     *
     * @return list<int>
     */
    public function feedbackRecipientIds(): array;
}
