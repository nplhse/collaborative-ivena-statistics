<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for AdminNotificationRecipientResolverInterface. */
#[AsAlias(AdminNotificationRecipientResolverInterface::class)]
final readonly class AdminNotificationRecipientResolver implements AdminNotificationRecipientResolverInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    #[\Override]
    public function recipientIds(): array
    {
        $ids = array_map(
            static fn (User $user): ?int => $user->getId(),
            $this->userRepository->findEnabledVerifiedUsersWithRoles([UserRole::ADMIN]),
        );

        return array_values(array_filter($ids, static fn (?int $id): bool => null !== $id));
    }
}
