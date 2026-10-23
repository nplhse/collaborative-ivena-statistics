<?php

declare(strict_types=1);

namespace App\User\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Notification\AdminUserRegisteredNotification;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the administrators about a new registration (`admin.user_registered`). A user registers once,
 * so the deduplication key is the user id. Only the username is stored; the email adds the links.
 */
#[AsEventListener(event: UserRegistered::class, method: 'onUserRegistered')]
final readonly class UserRegisteredNotificationSubscriber
{
    public function __construct(
        private UserRepository $userRepository,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        $user = $this->userRepository->find($event->userId);
        $userId = $user?->getId();
        if (!$user instanceof User || null === $userId) {
            return;
        }

        $recipientIds = $this->recipients->systemRecipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: AdminUserRegisteredNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%d', AdminUserRegisteredNotification::TYPE, $userId),
            parameters: [
                'userId' => $userId,
                'username' => $user->getUserIdentifier(),
            ],
            action: new NotificationAction('app_admin_dashboard_user_detail', ['entityId' => $userId]),
            metadata: ['reference' => \sprintf('user:%d', $userId)],
        ));
    }
}
