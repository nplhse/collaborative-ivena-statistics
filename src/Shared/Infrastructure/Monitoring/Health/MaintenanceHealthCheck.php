<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Shared\Infrastructure\Maintenance\MaintenanceMode;

/**
 * Maintenance mode in Application Health (#1680, ADR 046): `degraded` while it is on, so a mode that was not switched
 * off after a deploy shows up in monitoring. `/health` keeps answering 200 and stays reachable during the maintenance.
 * Not public: the overall status shows it, the admin dashboard names the check.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class MaintenanceHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'maintenance';

    public function __construct(
        private MaintenanceMode $maintenanceMode,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        return $this->maintenanceMode->isEnabled()
            ? new HealthCheckResult(self::KEY, HealthCheckStatus::Degraded, 'on')
            : new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'off');
    }
}
