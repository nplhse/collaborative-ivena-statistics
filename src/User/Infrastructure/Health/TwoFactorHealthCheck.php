<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorSecretCipher;
use Doctrine\DBAL\Connection;

/**
 * Two-factor key in Application Health (#1357, ADR 040). Without `APP_TWO_FACTOR_KEY` the feature is simply off
 * (`disabled`, healthy). Degraded when accounts use two-factor authentication but the key is missing (`missing_key`)
 * or does not decrypt their secrets (`key_mismatch`): their codes are rejected, only backup codes work. At most two queries,
 * one decryption. Not public.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class TwoFactorHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'two_factor';

    public function __construct(
        private Connection $connection,
        private TwoFactorSecretCipher $cipher,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $sample = $this->connection->fetchOne(
            'SELECT totp_secret_encrypted FROM "user" WHERE totp_enabled_at IS NOT NULL AND totp_secret_encrypted IS NOT NULL LIMIT 1',
        );

        if (!$this->cipher->isConfigured()) {
            return \is_string($sample)
                ? new HealthCheckResult(self::KEY, HealthCheckStatus::Degraded, 'missing_key users='.$this->countUsers())
                : new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'disabled');
        }

        if (\is_string($sample) && null === $this->cipher->decrypt($sample)) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Degraded, 'key_mismatch');
        }

        return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
    }

    private function countUsers(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE totp_enabled_at IS NOT NULL');
    }
}
