<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** @psalm-suppress UnusedClass */
final class UserAccountStatusChecker implements UserCheckerInterface
{
    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
        $this->assertAccountEnabled($user);
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user): void
    {
        $this->assertAccountEnabled($user);
    }

    private function assertAccountEnabled(UserInterface $user): void
    {
        if (!$user instanceof User || $user->isEnabled()) {
            return;
        }

        throw new CustomUserMessageAccountStatusException('Account is disabled.');
    }
}
