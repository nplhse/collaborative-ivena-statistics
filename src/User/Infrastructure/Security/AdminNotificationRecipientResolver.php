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
    public function systemRecipientIds(): array
    {
        return $this->idsWithRoles([UserRole::ADMIN, UserRole::RECEIVES_NOTIFICATION]);
    }

    #[\Override]
    public function feedbackRecipientIds(): array
    {
        return $this->idsWithRoles([UserRole::ADMIN, UserRole::FEEDBACK_RECIPIENT]);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<int>
     */
    private function idsWithRoles(array $roles): array
    {
        $ids = array_map(
            static fn (User $user): ?int => $user->getId(),
            $this->userRepository->findEnabledVerifiedUsersWithRoles($roles),
        );

        return array_values(array_filter($ids, static fn (?int $id): bool => null !== $id));
    }
}
