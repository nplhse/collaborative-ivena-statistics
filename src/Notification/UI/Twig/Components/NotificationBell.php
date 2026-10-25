<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig\Components;

use App\Notification\UI\Http\SafeTargetPath;
use App\Notification\UI\Twig\CurrentUserNotificationState;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Header bell with unread badge. The badge is one primary-key lookup; the dropdown content is
 * loaded lazily when the menu opens (ADR 016, "In-app UI").
 */
#[AsTwigComponent(name: 'NotificationBell', template: '@Notification/components/NotificationBell.html.twig')]
final readonly class NotificationBell
{
    public function __construct(
        private CurrentUserNotificationState $state,
        private RequestStack $requestStack,
    ) {
    }

    public function getUnreadCount(): int
    {
        return $this->state->snapshot()->unreadCount;
    }

    /**
     * Rendered with every page so polling can start from the current state (ADR 016, "Polling and initial rendering").
     */
    public function getVersion(): int
    {
        return $this->state->snapshot()->version;
    }

    public function getCurrentPath(): string
    {
        return SafeTargetPath::sanitize($this->requestStack->getCurrentRequest()?->getRequestUri()) ?? '/';
    }
}
