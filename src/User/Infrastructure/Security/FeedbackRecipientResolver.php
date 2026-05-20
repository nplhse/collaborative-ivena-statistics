<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;

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
        /** @var list<User> $candidates */
        $candidates = $this->userRepository->createQueryBuilder('u')
            ->where('u.isEnabled = true')
            ->andWhere('u.isVerified = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere("u.email != ''")
            ->getQuery()
            ->getResult();

        $emails = [];
        foreach ($candidates as $user) {
            $roles = $user->getRoles();
            if (!\in_array(UserRole::ADMIN, $roles, true) || !\in_array(UserRole::FEEDBACK_RECIPIENT, $roles, true)) {
                continue;
            }

            $email = $user->getEmail();
            if (null === $email || '' === trim($email)) {
                continue;
            }

            $emails[] = mb_strtolower(trim($email));
        }

        return array_values(array_unique($emails));
    }
}
