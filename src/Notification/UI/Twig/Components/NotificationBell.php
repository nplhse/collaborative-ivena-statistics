<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig\Components;

use App\Notification\Application\Contract\NotificationInboxQueryInterface;
use App\Notification\Application\Inbox\RecipientInboxState;
use App\Notification\UI\Http\SafeTargetPath;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Header bell with unread badge. The badge is one primary-key lookup; the dropdown content is
 * loaded lazily when the menu opens (ADR 016, "In-app UI").
 */
#[AsTwigComponent(name: 'NotificationBell', template: '@Notification/components/NotificationBell.html.twig')]
final class NotificationBell
{
    private ?RecipientInboxState $state = null;

    public function __construct(
        private readonly Security $security,
        private readonly NotificationInboxQueryInterface $inboxQuery,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getUnreadCount(): int
    {
        return $this->state()->unreadCount;
    }

    /**
     * Rendered with every page so polling can start from the current state (ADR 016, "Polling and initial rendering").
     */
    public function getVersion(): int
    {
        return $this->state()->version;
    }

    public function getCurrentPath(): string
    {
        return SafeTargetPath::sanitize($this->requestStack->getCurrentRequest()?->getRequestUri()) ?? '/';
    }

    private function state(): RecipientInboxState
    {
        if ($this->state instanceof RecipientInboxState) {
            return $this->state;
        }

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        return $this->state = null === $userId ? new RecipientInboxState() : $this->inboxQuery->state($userId);
    }
}
