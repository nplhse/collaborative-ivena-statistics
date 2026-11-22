<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\TwoFactor;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

/**
 * A secret during setup, before its first code is confirmed (#1357). Lets scheb's TOTP authenticator build the QR code
 * and check the first code without touching the account.
 */
final readonly class PendingTotpSetup implements TwoFactorInterface
{
    public function __construct(
        private string $username,
        private string $secret,
    ) {
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    #[\Override]
    public function isTotpAuthenticationEnabled(): bool
    {
        return true;
    }

    #[\Override]
    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    #[\Override]
    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }
}
