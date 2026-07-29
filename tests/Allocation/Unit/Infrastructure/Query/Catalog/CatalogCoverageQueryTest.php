<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Query\Catalog;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Allocation\Application\Explore\Catalog\CatalogPrivacyPolicy;
use App\Allocation\Infrastructure\Query\Catalog\CatalogCoverageQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class CatalogCoverageQueryTest extends TestCase
{
    public function testReturnsEmptyCoverageWhenNoRowsMatch(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'allocation_count' => 0,
            'hospital_count' => 0,
            'dispatch_area_count' => 0,
            'state_count' => 0,
            'first_at' => null,
            'last_at' => null,
        ]);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $query = new CatalogCoverageQuery($connection);
        $coverage = $query->forDimension(CatalogDimensionKey::Indication, 1);

        self::assertFalse($coverage->hasData());
        self::assertFalse($coverage->suppressed);
    }

    public function testSuppressesDetailedMetricsBelowThreshold(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'allocation_count' => CatalogPrivacyPolicy::MIN_ALLOCATIONS - 1,
            'hospital_count' => 2,
            'dispatch_area_count' => 1,
            'state_count' => 1,
            'first_at' => '2024-01-01 00:00:00',
            'last_at' => '2024-06-01 00:00:00',
        ]);
        $connection->method('fetchOne')->willReturn(100);
        $connection->method('fetchAllAssociative')->willReturn([
            ['year' => 2024, 'count' => 4],
        ]);

        $query = new CatalogCoverageQuery($connection);
        $coverage = $query->forDimension(CatalogDimensionKey::SecondaryTransport, 55);

        self::assertTrue($coverage->hasData());
        self::assertTrue($coverage->suppressed);
        self::assertSame(CatalogPrivacyPolicy::MIN_ALLOCATIONS - 1, $coverage->allocationCount);
        self::assertSame(0, $coverage->hospitalCount);
        self::assertNull($coverage->firstAt);
        self::assertSame([], $coverage->years);
        self::assertNull($coverage->sharePercent());
    }

    public function testReturnsFullMetricsWhenAboveThreshold(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'allocation_count' => 10,
            'hospital_count' => 3,
            'dispatch_area_count' => 2,
            'state_count' => 1,
            'first_at' => '2023-01-01 00:00:00',
            'last_at' => '2025-01-01 00:00:00',
        ]);
        $connection->method('fetchOne')->willReturn(50);
        $connection->method('fetchAllAssociative')->willReturn([
            ['year' => 2023, 'count' => 4],
            ['year' => 2025, 'count' => 6],
        ]);

        $query = new CatalogCoverageQuery($connection);
        $coverage = $query->forDimension(CatalogDimensionKey::Department, 9);

        self::assertTrue($coverage->hasData());
        self::assertFalse($coverage->suppressed);
        self::assertSame(10, $coverage->allocationCount);
        self::assertSame(3, $coverage->hospitalCount);
        self::assertSame(20.0, $coverage->sharePercent());
        self::assertCount(2, $coverage->years);
        self::assertNotNull($coverage->firstAt);
        self::assertNotNull($coverage->lastAt);
    }

    public function testForIndicationIdsReturnsEmptyWhenNoMembers(): void
    {
        $connection = $this->createStub(Connection::class);
        $query = new CatalogCoverageQuery($connection);

        $coverage = $query->forIndicationIds([]);

        self::assertFalse($coverage->hasData());
    }

    public function testForIndicationIdsAggregatesMemberCoverage(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'allocation_count' => 12,
            'hospital_count' => 4,
            'dispatch_area_count' => 2,
            'state_count' => 1,
            'first_at' => '2023-01-01 00:00:00',
            'last_at' => '2025-01-01 00:00:00',
        ]);
        $connection->method('fetchOne')->willReturn(40);
        $connection->method('fetchAllAssociative')->willReturn([
            ['year' => 2023, 'count' => 5],
            ['year' => 2025, 'count' => 7],
        ]);

        $query = new CatalogCoverageQuery($connection);
        $coverage = $query->forIndicationIds([1, 2, 3]);

        self::assertTrue($coverage->hasData());
        self::assertFalse($coverage->suppressed);
        self::assertSame(12, $coverage->allocationCount);
        self::assertSame(30.0, $coverage->sharePercent());
    }

    public function testForHospitalHidesAllVolumeWithoutViewPermission(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'allocation_count' => 3,
            'hospital_count' => 1,
            'dispatch_area_count' => 1,
            'state_count' => 1,
            'first_at' => '2024-01-01 00:00:00',
            'last_at' => '2024-06-01 00:00:00',
        ]);
        $connection->method('fetchOne')->willReturn(100);
        $connection->method('fetchAllAssociative')->willReturn([
            ['year' => 2024, 'count' => 3],
        ]);

        $query = new CatalogCoverageQuery($connection);
        $restricted = $query->forHospital(42, false);
        $revealed = $query->forHospital(42, true);

        self::assertTrue($restricted->hasData());
        self::assertFalse($restricted->suppressed);
        self::assertSame(0, $restricted->allocationCount);
        self::assertSame(0, $restricted->totalAllocationCount);
        self::assertFalse($restricted->revealSensitiveMetrics);
        self::assertNull($restricted->sharePercent());
        self::assertCount(1, $restricted->years);
        self::assertSame(1, $restricted->years[0]['count']);
        self::assertNotNull($restricted->firstAt);

        self::assertTrue($revealed->revealSensitiveMetrics);
        self::assertSame(3, $revealed->allocationCount);
        self::assertSame(3.0, $revealed->sharePercent());
        self::assertSame(3, $revealed->years[0]['count']);
    }
}
