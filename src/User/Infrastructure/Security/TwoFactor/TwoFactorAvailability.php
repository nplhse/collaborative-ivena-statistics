<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether users can set up two-factor authentication (#1357): only with a valid `APP_TWO_FACTOR_KEY` and never in the
 * demo environment, whose accounts are shared (ADR 023). Accounts that already use it keep being asked for a code.
 * Administrators must use it when `app.security.two_factor.required_for_admins` is on and it is available.
 */
final readonly class TwoFactorAvailability
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TwoFactorSecretCipher $cipher,
        #[Autowire(param: 'app.demo_mode')]
        private bool $demoMode,
        #[Autowire(param: 'app.security.two_factor.required_for_admins')]
        private bool $requiredForAdmins = false,
    ) {
    }

    public function isAvailable(): bool
    {
        return !$this->demoMode && $this->cipher->isConfigured();
    }

    /** Whether the obligation for administrators applies at all; cheap, no user needed. */
    public function isRequiredForAdmins(): bool
    {
        return $this->requiredForAdmins && $this->isAvailable();
    }

    /** Whether this user must have two-factor authentication: administrators, when the obligation is on. */
    public function isRequiredFor(User $user): bool
    {
        return $this->isRequiredForAdmins() && \in_array(UserRole::ADMIN, $user->getRoles(), true);
    }
}
