<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Geo;

use App\Allocation\Application\Geo\DTO\DispatchAreaShapeStatus;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryStatus;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchFailure;
use App\Allocation\Domain\Enum\DistrictBoundaryFetchStatus;
use PHPUnit\Framework\TestCase;

final class DistrictBoundaryStatusTest extends TestCase
{
    private const string NOW = '2026-11-23 12:00:00';

    public function testAStoredGeometryIsReadyEvenWithAnOldFetchState(): void
    {
        $status = $this->boundaryStatus(true, DistrictBoundaryFetchStatus::Running, null, '2026-11-23 10:00:00');

        self::assertSame(DistrictBoundaryStatus::READY, $status->state);
        self::assertFalse($status->canFetch());
    }

    public function testAMissingGeometryCanBeFetched(): void
    {
        $status = $this->boundaryStatus(false, DistrictBoundaryFetchStatus::Idle, null, null);

        self::assertSame(DistrictBoundaryStatus::MISSING, $status->state);
        self::assertTrue($status->canFetch());
    }

    public function testARunningFetchCannotBeStartedAgainWithinTheLimit(): void
    {
        $status = $this->boundaryStatus(false, DistrictBoundaryFetchStatus::Running, null, '2026-11-23 11:45:00');

        self::assertSame(DistrictBoundaryStatus::RUNNING, $status->state);
        self::assertFalse($status->canFetch());
    }

    public function testAFetchOlderThanTheLimitCountsAsFailed(): void
    {
        $status = $this->boundaryStatus(false, DistrictBoundaryFetchStatus::Running, null, '2026-11-23 11:44:59');

        self::assertSame(DistrictBoundaryStatus::FAILED, $status->state);
        self::assertSame(DistrictBoundaryFetchFailure::TimedOut, $status->failure);
        self::assertTrue($status->canFetch());
    }

    public function testAFailedFetchKeepsItsReason(): void
    {
        $status = $this->boundaryStatus(false, DistrictBoundaryFetchStatus::Failed, 'not_found', null);

        self::assertSame(DistrictBoundaryFetchFailure::NotFound, $status->failure);
        self::assertTrue($status->canFetch());
    }

    public function testShapeNeedsDistrictsAndEveryGeometry(): void
    {
        self::assertFalse(new DispatchAreaShapeStatus('Area', true, 0)->hasShape());
        self::assertFalse(new DispatchAreaShapeStatus('Area', true, 2, ['06002 South'])->hasShape());
        self::assertTrue(new DispatchAreaShapeStatus('Area', true, 2)->hasShape());
    }

    private function boundaryStatus(bool $hasGeometry, DistrictBoundaryFetchStatus $fetchStatus, ?string $error, ?string $requestedAt): DistrictBoundaryStatus
    {
        return DistrictBoundaryStatus::from(
            $hasGeometry,
            $fetchStatus,
            $error,
            null === $requestedAt ? null : new \DateTimeImmutable($requestedAt),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
