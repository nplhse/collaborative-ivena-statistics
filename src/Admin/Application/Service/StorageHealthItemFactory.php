<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Admin\Application\DTO\HealthCheckItemDto;
use App\Admin\Application\DTO\StorageHealthDetailsDto;
use App\Shared\Application\Health\HealthCheckStatus;
use App\Storage\Application\ByteFormatter;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Health\StorageHealthState;
use App\Storage\Domain\Entity\StorageMeasurement;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Storage item of the Admin health card: the health value plus usage details of the latest measurement.
 */
final readonly class StorageHealthItemFactory
{
    /**
     * Target of the item link. Switches to `app_admin_dashboard_operations_storage` (Disk Usage page)
     * once that page exists (ADR 017, step 4/5).
     */
    public const string DISK_USAGE_ROUTE = 'app_admin_dashboard';

    public function __construct(
        private StorageMeasurementRepositoryInterface $measurements,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(string $value): HealthCheckItemDto
    {
        $status = StorageHealthState::tryFrom($value)?->status() ?? HealthCheckStatus::Degraded;
        $measurement = $this->measurements->latest();

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
            storage: $measurement instanceof StorageMeasurement ? $this->details($measurement) : null,
        );
    }

    private function details(StorageMeasurement $measurement): StorageHealthDetailsDto
    {
        $used = $measurement->getUsedBytes();
        $capacity = $measurement->getCapacityBytes();

        return new StorageHealthDetailsDto(
            used: null === $used ? null : ByteFormatter::format($used),
            capacity: null === $capacity ? null : ByteFormatter::format($capacity),
            capacitySourceLabelKey: 'ops.health.storage.source.'.$measurement->getCapacitySource()->value,
            remainingPercent: null === $used || null === $capacity
                ? null
                : max(0.0, round((float) ($capacity - $used) / (float) $capacity * 100.0, 1)),
            levelLabelKey: 'ops.health.storage.level.'.$measurement->getLevel()->value,
            measuredAt: $measurement->getMeasuredAt(),
        );
    }
}
