<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig;

use App\Notification\Application\Snapshot\NotificationSnapshot;
use App\Notification\Application\Snapshot\NotificationSnapshotProvider;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Badge state of the signed-in user for the page render, fetched once per request.
 *
 * The header bell and the user menu both show the unread count; they share this lookup.
 * The page render never claims toasts; the first poll does (ADR 016).
 */
final class CurrentUserNotificationState implements ResetInterface
{
    /** @var array<int, NotificationSnapshot> */
    private array $snapshots = [];

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private readonly Security $security,
        private readonly NotificationSnapshotProvider $snapshotProvider,
    ) {
    }

    public function snapshot(): NotificationSnapshot
    {
        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        if (null === $userId) {
            return new NotificationSnapshot(0, 0);
        }

        return $this->snapshots[$userId] ??= $this->snapshotProvider->snapshot($userId);
    }

    #[\Override]
    public function reset(): void
    {
        $this->snapshots = [];
    }
}
