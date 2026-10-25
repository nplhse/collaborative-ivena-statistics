<?php

declare(strict_types=1);

namespace App\Storage\Application\Health;

use App\Shared\Application\Health\HealthCheckStatus;

/**
 * Storage state in Application Health (ADR 017, "Application Health"). The value is the public check string.
 */
enum StorageHealthState: string
{
    case Ok = 'ok';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';
    case Exceeded = 'exceeded';
    /** Measured, but there is no capacity reference to compare with. */
    case NotConfigured = 'not_configured';
    /** The latest measurement has no total because files or database could not be measured. */
    case Incomplete = 'incomplete';
    /** The latest measurement is older than the configured maximum age. */
    case Stale = 'stale';
    /** There is no measurement at all. */
    case Unavailable = 'unavailable';

    public function status(): HealthCheckStatus
    {
        return match ($this) {
            self::Ok, self::Notice, self::NotConfigured => HealthCheckStatus::Healthy,
            self::Warning, self::Incomplete, self::Stale, self::Unavailable => HealthCheckStatus::Degraded,
            self::Critical, self::Exceeded => HealthCheckStatus::Unhealthy,
        };
    }
}
