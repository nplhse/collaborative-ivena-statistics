<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig\Components;

use App\Notification\UI\Twig\CurrentUserNotificationState;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Notifications" entry of the user menu with the unread count. Polling keeps the count in step
 * with the header bell (notification-poll and notification-count controllers).
 */
#[AsTwigComponent(name: 'NotificationMenuItem', template: '@Notification/components/NotificationMenuItem.html.twig')]
final readonly class NotificationMenuItem
{
    public function __construct(
        private CurrentUserNotificationState $state,
    ) {
    }

    public function getUnreadCount(): int
    {
        return $this->state->snapshot()->unreadCount;
    }
}
