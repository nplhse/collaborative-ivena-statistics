<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** @psalm-suppress UnusedClass */
final class UserAccountStatusChecker implements UserCheckerInterface
{
    #[\Override]
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertAccountEnabled($user);
        $this->assertEmailVerified($user);
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertAccountEnabled($user);
    }

    private function assertAccountEnabled(UserInterface $user): void
    {
        if (!$user instanceof User || $user->isEnabled()) {
            return;
        }

        throw new BadCredentialsException('Invalid credentials.');
    }

    private function assertEmailVerified(UserInterface $user): void
    {
        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        throw new BadCredentialsException('Invalid credentials.');
    }
}
