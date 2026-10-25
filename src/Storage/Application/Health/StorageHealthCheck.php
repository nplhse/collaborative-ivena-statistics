<?php

declare(strict_types=1);

namespace App\Storage\Application\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use Psr\Clock\ClockInterface;

/**
 * Storage capacity in Application Health (ADR 017). Reads only the latest stored measurement (one indexed query,
 * never a filesystem scan). Public: `/health` shows only the state string.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class StorageHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'storage';

    public function __construct(
        private StorageMeasurementRepositoryInterface $measurements,
        private StorageSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $state = $this->state();

        return new HealthCheckResult(self::KEY, $state->status(), $state->value, public: true);
    }

    private function state(): StorageHealthState
    {
        $measurement = $this->measurements->latest();

        return $measurement instanceof StorageMeasurement ? $this->stateOf($measurement) : StorageHealthState::Unavailable;
    }

    private function stateOf(StorageMeasurement $measurement): StorageHealthState
    {
        $oldestFresh = $this->clock->now()->modify(sprintf('-%d hours', $this->settings->measurementMaxAgeHours));
        if ($measurement->getMeasuredAt() < $oldestFresh) {
            return StorageHealthState::Stale;
        }

        if (null === $measurement->getUsedBytes()) {
            return StorageHealthState::Incomplete;
        }

        if (CapacitySource::None === $measurement->getCapacitySource()) {
            return StorageHealthState::NotConfigured;
        }

        return match ($measurement->getLevel()) {
            CapacityLevel::Normal => StorageHealthState::Ok,
            CapacityLevel::Notice => StorageHealthState::Notice,
            CapacityLevel::Warning => StorageHealthState::Warning,
            CapacityLevel::Critical => StorageHealthState::Critical,
            CapacityLevel::Exceeded => StorageHealthState::Exceeded,
            CapacityLevel::Unknown => StorageHealthState::Incomplete,
        };
    }
}
