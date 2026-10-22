<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Inbox\ClaimedToast;

/**
 * Claims the pending toasts of one recipient for an active browser context.
 *
 * A claim means "handed to a browser", not "seen": each toast is claimed at most once, and a toast
 * whose response is lost stays only in the inbox (ADR 016, best effort).
 */
interface NotificationToastClaimerInterface
{
    /**
     * Claims unread, visible toasts whose window is still open, oldest first.
     *
     * @return list<ClaimedToast>
     */
    public function claim(int $recipientId, \DateTimeImmutable $now, int $limit): array;
}
