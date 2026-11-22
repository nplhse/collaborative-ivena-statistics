<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether users can set up two-factor authentication (#1357): only with a valid `APP_TWO_FACTOR_KEY` and never in the
 * demo environment, whose accounts are shared (ADR 023). Accounts that already use it keep being asked for a code.
 */
final readonly class TwoFactorAvailability
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TwoFactorSecretCipher $cipher,
        #[Autowire(param: 'app.demo_mode')]
        private bool $demoMode,
    ) {
    }

    public function isAvailable(): bool
    {
        return !$this->demoMode && $this->cipher->isConfigured();
    }
}
