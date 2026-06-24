<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

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
     * @return list<string>
     */
    #[\Override]
    public function resolveRecipientEmails(): array
    {
        $candidates = $this->userRepository->findEnabledVerifiedUsersWithRoles([
            UserRole::ADMIN,
            UserRole::FEEDBACK_RECIPIENT,
        ]);

        $emails = [];
        foreach ($candidates as $user) {
            $email = $user->getEmail();
            if (null === $email || '' === trim($email)) {
                continue;
            }

            $emails[] = mb_strtolower(trim($email));
        }

        return array_values(array_unique($emails));
    }
}
