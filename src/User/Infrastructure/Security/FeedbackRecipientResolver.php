<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass */
#[AsAlias(FeedbackRecipientEmailResolver::class)]
final readonly class FeedbackRecipientResolver implements FeedbackRecipientEmailResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    #[\Override]
    public function resolveRecipientUsers(): array
    {
        return $this->userRepository->findEnabledVerifiedUsersWithRoles([
            UserRole::ADMIN,
            UserRole::FEEDBACK_RECIPIENT,
        ]);
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function resolveRecipientEmails(): array
    {
        $emails = [];
        foreach ($this->resolveRecipientUsers() as $user) {
            $email = $user->getEmail();
            if (null === $email || '' === trim($email)) {
                continue;
            }

            $emails[] = mb_strtolower(trim($email));
        }

        return array_values(array_unique($emails));
    }
}
