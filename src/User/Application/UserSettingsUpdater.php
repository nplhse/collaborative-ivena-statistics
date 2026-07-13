<?php

declare(strict_types=1);

namespace App\User\Application;

use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserSettingsUpdater
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function updateEmail(User $user, string $email): void
    {
        $user->setEmail($email);
        $user->setIsVerified(false);
        $this->entityManager->flush();
    }

    public function updatePassword(User $user, string $hashedPassword): void
    {
        $user->setPassword($hashedPassword);
        $user->setCredentialsExpired(false);
        $this->entityManager->flush();
    }

    public function updateNotifications(User $user, bool $receivesMonthlySubmissionReminder): void
    {
        $user->setReceivesMonthlySubmissionReminder($receivesMonthlySubmissionReminder);
        $this->entityManager->flush();
    }
}
