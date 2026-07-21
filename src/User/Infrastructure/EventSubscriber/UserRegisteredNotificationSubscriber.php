<?php

declare(strict_types=1);

namespace App\User\Infrastructure\EventSubscriber;

use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationSenderInterface;
use App\Shared\Application\Notification\AdminNotificationType;
use App\User\Application\Contract\AdminUserDetailUrlGeneratorInterface;
use App\User\Application\Contract\GrantParticipantUrlGeneratorInterface;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: UserRegistered::class, method: 'onUserRegistered')]
final readonly class UserRegisteredNotificationSubscriber
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
        private AdminUserDetailUrlGeneratorInterface $adminUserUrlGenerator,
        private GrantParticipantUrlGeneratorInterface $grantParticipantUrlGenerator,
        private AdminNotificationSenderInterface $adminNotificationSender,
    ) {
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        $user = $this->userRepository->find($event->userId);
        if (!$user instanceof User) {
            return;
        }

        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $this->adminNotificationSender->send(new AdminNotification(
            type: AdminNotificationType::UserRegistered,
            templateContext: [
                'username' => $user->getUserIdentifier(),
                'userEmail' => $user->getEmail() ?? '—',
                'registeredAt' => new \DateTimeImmutable(),
                'userManagementUrl' => $this->adminUserUrlGenerator->detailUrl($userId),
                'grantParticipantUrl' => $this->grantParticipantUrlGenerator->generate($userId),
            ],
            referenceId: (string) $userId,
        ));
    }
}
