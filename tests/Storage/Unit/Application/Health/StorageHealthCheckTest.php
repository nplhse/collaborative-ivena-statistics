<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Health;

use App\Shared\Application\Health\HealthCheckStatus;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Health\StorageHealthCheck;
use App\Storage\Application\Health\StorageHealthState;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Domain\Enum\CapacityLevel;
use App\Storage\Domain\Enum\CapacitySource;
use App\Tests\Support\Storage\StorageMeasurementBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StorageHealthCheckTest extends TestCase
{
    private const string NOW = '2026-10-25 12:00:00';

    /**
     * @return iterable<string, array{CapacityLevel, StorageHealthState, HealthCheckStatus}>
     */
    public static function levels(): iterable
    {
        yield 'normal' => [CapacityLevel::Normal, StorageHealthState::Ok, HealthCheckStatus::Healthy];
        yield 'notice' => [CapacityLevel::Notice, StorageHealthState::Notice, HealthCheckStatus::Healthy];
        yield 'warning' => [CapacityLevel::Warning, StorageHealthState::Warning, HealthCheckStatus::Degraded];
        yield 'critical' => [CapacityLevel::Critical, StorageHealthState::Critical, HealthCheckStatus::Unhealthy];
        yield 'exceeded' => [CapacityLevel::Exceeded, StorageHealthState::Exceeded, HealthCheckStatus::Unhealthy];
        yield 'unknown with reference' => [CapacityLevel::Unknown, StorageHealthState::Incomplete, HealthCheckStatus::Degraded];
    }

    #[DataProvider('levels')]
    public function testMapsTheLevelOfAFreshMeasurement(CapacityLevel $level, StorageHealthState $state, HealthCheckStatus $status): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable('2026-10-25 03:30'), $level);

        $result = $this->check($measurement)->check();

        self::assertSame($state->value, $result->value);
        self::assertSame($status, $result->status);
    }

    public function testWithoutCapacityReferenceIsNotConfiguredAndHealthy(): void
    {
        $measurement = StorageMeasurementBuilder::create(
            new \DateTimeImmutable('2026-10-25 03:30'),
            CapacityLevel::Unknown,
            source: CapacitySource::None,
        );

        $result = $this->check($measurement)->check();

        self::assertSame('not_configured', $result->value);
        self::assertSame(HealthCheckStatus::Healthy, $result->status);
    }

    public function testWithoutTotalIsIncompleteAndDegradedEvenWithoutReference(): void
    {
        $measurement = StorageMeasurementBuilder::create(
            new \DateTimeImmutable('2026-10-25 03:30'),
            CapacityLevel::Unknown,
            usedBytes: null,
            source: CapacitySource::None,
        );

        $result = $this->check($measurement)->check();

        self::assertSame('incomplete', $result->value);
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function testAMeasurementOlderThanTheMaximumAgeIsStaleAndDegraded(): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable('2026-10-24 11:59:59'));

        $result = $this->check($measurement, maxAgeHours: 24)->check();

        self::assertSame('stale', $result->value);
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function testAMeasurementExactlyAtTheMaximumAgeIsStillFresh(): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable('2026-10-24 12:00:00'));

        self::assertSame('ok', $this->check($measurement, maxAgeHours: 24)->check()->value);
    }

    public function testAStaleCriticalMeasurementIsNotReportedAsCurrentLevel(): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable('2026-10-20 03:30'), CapacityLevel::Critical);

        $result = $this->check($measurement)->check();

        self::assertSame('stale', $result->value);
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function testWithoutMeasurementIsUnavailableAndDegraded(): void
    {
        $result = $this->check(null)->check();

        self::assertSame('unavailable', $result->value);
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function testContributesAPublicStorageCheckWithTheStateString(): void
    {
        $measurement = StorageMeasurementBuilder::create(new \DateTimeImmutable('2026-10-25 03:30'), CapacityLevel::Warning);

        $result = $this->check($measurement)->check();

        self::assertSame('storage', $result->key);
        self::assertSame('warning', $result->value);
        self::assertSame(HealthCheckStatus::Degraded, $result->status);
        self::assertTrue($result->public);
    }

    private function check(?StorageMeasurement $latest, int $maxAgeHours = 36): StorageHealthCheck
    {
        $repository = $this->createMock(StorageMeasurementRepositoryInterface::class);
        $repository->expects(self::once())->method('latest')->willReturn($latest);
        $repository->expects(self::never())->method('findSince');

        return new StorageHealthCheck(
            $repository,
            new StorageSettings(80, 90, 99, 10 * 1024 ** 3, 'budget', $maxAgeHours),
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }
}
