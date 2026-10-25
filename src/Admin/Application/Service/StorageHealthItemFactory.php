<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Storage\Application\Health\StorageHealthState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Storage item of the Admin health card: label and health value like the other checks, linking to the
 * Disk Usage page, where usage and level are shown in detail.
 */
final readonly class StorageHealthItemFactory
{
    /** Target of the item link: the Disk Usage page. */
    public const string DISK_USAGE_ROUTE = 'app_admin_dashboard_operations_storage';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(string $value): HealthCheckItemDto
    {
        $status = StorageHealthState::tryFrom($value)?->status() ?? HealthCheckStatus::Degraded;

        return new HealthCheckItemDto(
            key: 'storage',
            labelKey: 'ops.health.check.storage',
            value: $value,
            severity: match ($status) {
                HealthCheckStatus::Healthy => 'success',
                HealthCheckStatus::Degraded => 'warning',
                HealthCheckStatus::Unhealthy => 'danger',
            },
            icon: HealthCheckStatus::Healthy === $status ? 'fas fa-hard-drive' : 'fas fa-triangle-exclamation',
            detailUrl: $this->urlGenerator->generate(self::DISK_USAGE_ROUTE),
        );
    }
}
