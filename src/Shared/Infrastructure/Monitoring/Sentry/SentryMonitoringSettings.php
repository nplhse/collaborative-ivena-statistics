<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Sentry;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this installation sends data to Sentry and where Sentry stores it. The cookie consent shows the
 * performance option and the monitoring notices only when Sentry is used, and names the location.
 *
 * The location comes from `SENTRY_DATA_LOCATION` (`eu`, `us`, `self_hosted`) or, when that is empty, from the DSN
 * host: `*.de.sentry.io` is Sentry's EU region, other `sentry.io` hosts are the US region, any other host is a
 * self-hosted Sentry.
 */
final readonly class SentryMonitoringSettings
{
    public const string LOCATION_EU = 'eu';
    public const string LOCATION_US = 'us';
    public const string LOCATION_SELF_HOSTED = 'self_hosted';

    private const array LOCATIONS = [self::LOCATION_EU, self::LOCATION_US, self::LOCATION_SELF_HOSTED];

    public function __construct(
        #[Autowire('%env(default::SENTRY_DSN)%')]
        private ?string $dsn = null,
        #[Autowire('%env(default::SENTRY_DATA_LOCATION)%')]
        private ?string $configuredLocation = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->dsn && '' !== trim($this->dsn);
    }

    /**
     * @return self::LOCATION_*|null null when Sentry is not used
     */
    public function location(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $configured = strtolower(trim((string) $this->configuredLocation));
        if (\in_array($configured, self::LOCATIONS, true)) {
            return $configured;
        }

        $host = strtolower((string) parse_url((string) $this->dsn, \PHP_URL_HOST));
        if (str_ends_with($host, '.de.sentry.io')) {
            return self::LOCATION_EU;
        }
        if ('sentry.io' === $host || str_ends_with($host, '.sentry.io')) {
            return self::LOCATION_US;
        }

        return self::LOCATION_SELF_HOSTED;
    }
}
