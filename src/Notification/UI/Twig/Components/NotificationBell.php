<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig\Components;

use App\Notification\Application\Snapshot\NotificationSnapshot;
use App\Notification\Application\Snapshot\NotificationSnapshotProvider;
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
    private ?NotificationSnapshot $state = null;

    public function __construct(
        private readonly Security $security,
        private readonly NotificationSnapshotProvider $snapshotProvider,
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

    private function state(): NotificationSnapshot
    {
        if ($this->state instanceof NotificationSnapshot) {
            return $this->state;
        }

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;

        // The page render never claims toasts; the first poll does (ADR 016).
        return $this->state = null === $userId ? new NotificationSnapshot(0, 0) : $this->snapshotProvider->snapshot($userId);
    }
}
