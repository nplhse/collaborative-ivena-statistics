<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\User\Application\Contract\AdminUserDetailUrlGeneratorInterface;
use App\User\Application\Contract\GrantParticipantUrlGeneratorInterface;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

/**
 * The email of `admin.user_registered` keeps the former admin mail: username, registration time and
 * the links to manage the user or grant participation. The signed grant link is created at delivery
 * time, so it is never stored. If the user is gone by then, the generic notification email is sent.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class AdminUserRegisteredEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private AdminUserDetailUrlGeneratorInterface $adminUserUrlGenerator,
        private GrantParticipantUrlGeneratorInterface $grantParticipantUrlGenerator,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return AdminUserRegisteredNotification::TYPE === $type;
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $userId = $content->parameters['userId'] ?? null;
        $user = \is_int($userId) ? $this->userRepository->find($userId) : null;
        $id = $user?->getId();
        if (!$user instanceof User || null === $id) {
            return;
        }

        $content->htmlTemplate = '@User/email/admin_user_registered.html.twig';
        $content->context = [
            'title' => $content->context['title'] ?? $content->subject,
            'inboxUrl' => $content->context['inboxUrl'] ?? null,
            'username' => $user->getUserIdentifier(),
            'registeredAt' => $user->getCreatedAt(),
            'userManagementUrl' => $this->adminUserUrlGenerator->detailUrl($id),
            'grantParticipantUrl' => $this->grantParticipantUrlGenerator->generate($id),
        ];
    }
}
