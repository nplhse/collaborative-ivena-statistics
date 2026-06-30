<?php

declare(strict_types=1);

namespace App\Install\Application\Environment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

final readonly class EnvironmentChecker
{
    private const string DATABASE_CHECK_VARIABLE = 'DATABASE_CONNECTION';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private ?Connection $connection = null,
    ) {
    }

    /**
     * @param array<string, string> $variables
     */
    public function check(
        EnvironmentCheckProfile $profile,
        array $variables,
        bool $skipDatabase,
    ): EnvironmentCheckReport {
        $items = [
            $this->checkAppEnv($profile, $variables),
            $this->checkAppDebug($profile, $variables),
            $this->checkAppSecret($profile, $variables),
            $this->checkDatabaseUrl($profile, $variables),
            $this->checkAppUrl($profile, $variables),
            $this->checkMailerDsn($profile, $variables),
            $this->checkMailerFrom($profile, $variables),
            $this->checkMessengerTransportDsn($profile, $variables),
            $this->checkSentryDsn($profile, $variables),
            $this->checkSentryEnvironment($profile, $variables),
        ];

        if (!$skipDatabase) {
            $items[] = $this->checkDatabaseConnection($profile, $variables);
        }

        return new EnvironmentCheckReport($items);
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkAppEnv(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'APP_ENV');

        if ('prod' === $value) {
            return $this->item('APP_ENV', EnvironmentCheckStatus::Ok, 'set to prod');
        }

        if (!$profile->isStrict()) {
            return $this->item('APP_ENV', EnvironmentCheckStatus::Warn, sprintf('expected prod for deployment (current: %s)', $this->presenceLabel($value)));
        }

        return $this->item('APP_ENV', EnvironmentCheckStatus::Fail, sprintf('must be prod (current: %s)', $this->presenceLabel($value)));
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkAppDebug(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = strtolower($this->value($variables, 'APP_DEBUG'));

        if (\in_array($value, ['0', 'false', ''], true)) {
            return $this->item('APP_DEBUG', EnvironmentCheckStatus::Ok, 'disabled');
        }

        if (!$profile->isStrict()) {
            return $this->item('APP_DEBUG', EnvironmentCheckStatus::Warn, 'should be 0 in production');
        }

        return $this->item('APP_DEBUG', EnvironmentCheckStatus::Fail, 'must be 0 in production');
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkAppSecret(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'APP_SECRET');

        if ('' !== $value && strlen($value) >= 32) {
            return $this->item('APP_SECRET', EnvironmentCheckStatus::Ok, 'set (length ok)');
        }

        if ('' === $value) {
            $message = 'not set';

            return $this->item(
                'APP_SECRET',
                $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
                $message,
            );
        }

        return $this->item(
            'APP_SECRET',
            $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            'too short (minimum 32 characters)',
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkDatabaseUrl(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'DATABASE_URL');

        if ('' === $value) {
            return $this->item(
                'DATABASE_URL',
                $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
                'not set',
            );
        }

        if (!str_starts_with($value, 'postgresql://') && !str_starts_with($value, 'postgres://')) {
            return $this->item(
                'DATABASE_URL',
                $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
                'must use a PostgreSQL URL scheme',
            );
        }

        return $this->item('DATABASE_URL', EnvironmentCheckStatus::Ok, 'set (postgresql)');
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkAppUrl(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = trim($this->value($variables, 'APP_URL'));

        if ('' === $value) {
            return $this->item(
                'APP_URL',
                $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
                'not set',
            );
        }

        if (!$profile->isStrict()) {
            return $this->item('APP_URL', EnvironmentCheckStatus::Ok, 'set');
        }

        if (!str_starts_with($value, 'https://')) {
            return $this->item('APP_URL', EnvironmentCheckStatus::Fail, 'must use https:// in production');
        }

        if (str_contains($value, 'localhost') || str_contains($value, '127.0.0.1')) {
            return $this->item('APP_URL', EnvironmentCheckStatus::Fail, 'must not point to localhost in production');
        }

        return $this->item('APP_URL', EnvironmentCheckStatus::Ok, 'set (https)');
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkMailerDsn(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'MAILER_DSN');

        if ('' !== $value && 'null://null' !== $value) {
            return $this->item('MAILER_DSN', EnvironmentCheckStatus::Ok, 'set');
        }

        $message = 'null://null' === $value ? 'null transport configured' : 'not set';

        return $this->item(
            'MAILER_DSN',
            $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            $message,
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkMailerFrom(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = trim($this->value($variables, 'MAILER_FROM'));

        if ('' !== $value && false !== filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->item('MAILER_FROM', EnvironmentCheckStatus::Ok, 'valid email address');
        }

        return $this->item(
            'MAILER_FROM',
            $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            '' === $value ? 'not set' : 'invalid email address',
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkMessengerTransportDsn(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'MESSENGER_TRANSPORT_DSN');

        if ('' !== $value) {
            return $this->item('MESSENGER_TRANSPORT_DSN', EnvironmentCheckStatus::Ok, 'set');
        }

        return $this->item(
            'MESSENGER_TRANSPORT_DSN',
            $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            'not set',
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkSentryDsn(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'SENTRY_DSN');

        if ('' !== $value) {
            return $this->item('SENTRY_DSN', EnvironmentCheckStatus::Ok, 'set');
        }

        return $this->item(
            'SENTRY_DSN',
            $profile->requiresSentry() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            'not set (recommended for beta/production monitoring)',
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkSentryEnvironment(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $value = $this->value($variables, 'SENTRY_ENVIRONMENT');

        if ('' !== $value) {
            return $this->item('SENTRY_ENVIRONMENT', EnvironmentCheckStatus::Ok, 'set');
        }

        if (!$profile->isStrict()) {
            return $this->item('SENTRY_ENVIRONMENT', EnvironmentCheckStatus::Warn, 'not set (falls back to APP_ENV)');
        }

        return $this->item(
            'SENTRY_ENVIRONMENT',
            $profile->requiresSentry() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
            'not set (falls back to APP_ENV)',
        );
    }

    /**
     * @param array<string, string> $variables
     */
    private function checkDatabaseConnection(EnvironmentCheckProfile $profile, array $variables): EnvironmentCheckItem
    {
        $databaseUrl = $this->value($variables, 'DATABASE_URL');
        if ('' === $databaseUrl) {
            return $this->item(
                self::DATABASE_CHECK_VARIABLE,
                EnvironmentCheckStatus::Fail,
                'skipped because DATABASE_URL is not set',
            );
        }

        if (!$this->connection instanceof Connection) {
            return $this->item(
                self::DATABASE_CHECK_VARIABLE,
                $profile->isStrict() ? EnvironmentCheckStatus::Fail : EnvironmentCheckStatus::Warn,
                'database connection not available',
            );
        }

        try {
            $this->connection->executeQuery('SELECT 1');

            return $this->item(self::DATABASE_CHECK_VARIABLE, EnvironmentCheckStatus::Ok, 'reachable');
        } catch (Exception $exception) {
            return $this->item(
                self::DATABASE_CHECK_VARIABLE,
                EnvironmentCheckStatus::Fail,
                'connection failed: '.$exception->getMessage(),
            );
        }
    }

    /**
     * @param array<string, string> $variables
     */
    private function value(array $variables, string $key): string
    {
        return trim($variables[$key] ?? '');
    }

    private function presenceLabel(string $value): string
    {
        return '' === $value ? 'not set' : $value;
    }

    private function item(string $variable, EnvironmentCheckStatus $status, string $message): EnvironmentCheckItem
    {
        return new EnvironmentCheckItem($variable, $status, $message);
    }
}
