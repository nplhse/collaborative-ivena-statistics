<?php

declare(strict_types=1);

namespace App\Import\UI\Console;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

/**
 * Resolves the --notify-user option of import commands to the user stored as run initiator.
 */
final readonly class NotifyUserResolver
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @return int|null user id, or null when the option was not given
     *
     * @throws \InvalidArgumentException when no user matches the username
     */
    public function resolve(?string $username): ?int
    {
        if (null === $username) {
            return null;
        }

        $username = trim($username);
        $user = '' !== $username ? $this->userRepository->findOneBy(['username' => $username]) : null;
        $userId = $user instanceof User ? $user->getId() : null;

        if (null === $userId) {
            throw new \InvalidArgumentException(\sprintf('No user found with username "%s".', $username));
        }

        return $userId;
    }
}
