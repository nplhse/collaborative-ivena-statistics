<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use PHPUnit\Framework\TestCase;

final class CatalogCoverageTest extends TestCase
{
    public function testSharePercentIsNullWhenSuppressedOrEmptyTotal(): void
    {
        $suppressed = new CatalogCoverage(3, 100, 0, 0, 0, null, null, [], true);
        self::assertNull($suppressed->sharePercent());

        $emptyTotal = new CatalogCoverage(10, 0, 1, 1, 1, null, null, [], false);
        self::assertNull($emptyTotal->sharePercent());
    }

    public function testSharePercentRoundsToTwoDecimals(): void
    {
        $coverage = new CatalogCoverage(1, 3, 1, 1, 1, null, null, [], false);

        self::assertSame(33.33, $coverage->sharePercent());
    }

    public function testEmptyFactory(): void
    {
        $coverage = CatalogCoverage::empty();

        self::assertFalse($coverage->hasData());
        self::assertSame(0, $coverage->allocationCount);
        self::assertFalse($coverage->suppressed);
    }

    public function testHasDataWhenRestrictedCoverageKeepsPeriodWithoutVolume(): void
    {
        $coverage = new CatalogCoverage(
            allocationCount: 0,
            totalAllocationCount: 0,
            hospitalCount: 0,
            dispatchAreaCount: 0,
            stateCount: 0,
            firstAt: new \DateTimeImmutable('2024-01-01'),
            lastAt: new \DateTimeImmutable('2024-06-01'),
            years: [['year' => 2024, 'count' => 1]],
            suppressed: false,
            revealSensitiveMetrics: false,
        );

        self::assertTrue($coverage->hasData());
        self::assertNull($coverage->sharePercent());
    }

    public function testYearHeatmapStartsAt2015WithFiveColumns(): void
    {
        $coverage = new CatalogCoverage(
            allocationCount: 30,
            totalAllocationCount: 100,
            hospitalCount: 2,
            dispatchAreaCount: 1,
            stateCount: 1,
            firstAt: new \DateTimeImmutable('2016-01-01'),
            lastAt: new \DateTimeImmutable('2018-12-31'),
            years: [
                ['year' => 2016, 'count' => 10],
                ['year' => 2018, 'count' => 20],
            ],
            suppressed: false,
        );

        $rows = $coverage->yearHeatmap(currentYear: 2019);

        self::assertCount(1, $rows);
        self::assertCount(5, $rows[0]);
        self::assertSame(2015, $rows[0][0]['year']);
        self::assertSame(0, $rows[0][0]['count']);
        self::assertFalse($rows[0][0]['future']);
        self::assertFalse($rows[0][0]['hasData']);
        self::assertFalse($rows[0][0]['countHidden']);
        self::assertSame(0.0, $rows[0][0]['intensity']);
        self::assertSame(2016, $rows[0][1]['year']);
        self::assertSame(10, $rows[0][1]['count']);
        self::assertTrue($rows[0][1]['hasData']);
        self::assertFalse($rows[0][1]['countHidden']);
        self::assertSame(0.5, $rows[0][1]['intensity']);
        self::assertSame(2018, $rows[0][3]['year']);
        self::assertSame(20, $rows[0][3]['count']);
        self::assertSame(1.0, $rows[0][3]['intensity']);
        self::assertSame(2019, $rows[0][4]['year']);
        self::assertFalse($rows[0][4]['future']);
    }

    public function testYearHeatmapHidesCountsWhenSensitiveMetricsRestricted(): void
    {
        $coverage = new CatalogCoverage(
            allocationCount: 30,
            totalAllocationCount: 100,
            hospitalCount: 1,
            dispatchAreaCount: 1,
            stateCount: 1,
            firstAt: new \DateTimeImmutable('2016-01-01'),
            lastAt: new \DateTimeImmutable('2018-12-31'),
            years: [
                ['year' => 2016, 'count' => 10],
                ['year' => 2018, 'count' => 20],
            ],
            suppressed: false,
            revealSensitiveMetrics: false,
        );

        self::assertNull($coverage->sharePercent());

        $rows = $coverage->yearHeatmap(currentYear: 2019);
        $cell2016 = $rows[0][1];
        $cell2018 = $rows[0][3];
        $cellEmpty = $rows[0][0];

        self::assertTrue($cell2016['hasData']);
        self::assertTrue($cell2016['countHidden']);
        self::assertSame(0, $cell2016['count']);
        self::assertSame(0.55, $cell2016['intensity']);

        self::assertTrue($cell2018['hasData']);
        self::assertTrue($cell2018['countHidden']);
        self::assertSame(0, $cell2018['count']);
        self::assertSame(0.55, $cell2018['intensity']);

        self::assertFalse($cellEmpty['hasData']);
        self::assertFalse($cellEmpty['countHidden']);
        self::assertSame(0.0, $cellEmpty['intensity']);
    }

    public function testYearHeatmapPadsToFullRows(): void
    {
        $coverage = new CatalogCoverage(
            allocationCount: 5,
            totalAllocationCount: 5,
            hospitalCount: 1,
            dispatchAreaCount: 1,
            stateCount: 1,
            firstAt: new \DateTimeImmutable('2020-01-01'),
            lastAt: new \DateTimeImmutable('2021-12-31'),
            years: [
                ['year' => 2020, 'count' => 2],
                ['year' => 2021, 'count' => 3],
            ],
            suppressed: false,
        );

        $rows = $coverage->yearHeatmap(currentYear: 2022);

        self::assertCount(2, $rows);
        self::assertSame(2015, $rows[0][0]['year']);
        self::assertSame(2019, $rows[0][4]['year']);
        self::assertSame(2020, $rows[1][0]['year']);
        self::assertSame(2024, $rows[1][4]['year']);
        self::assertFalse($rows[1][2]['future']); // 2022
        self::assertTrue($rows[1][3]['future']); // 2023
        self::assertTrue($rows[1][4]['future']); // 2024
        self::assertSame(0, $rows[1][3]['count']);
    }

    public function testYearHeatmapMarksFutureYears(): void
    {
        $coverage = new CatalogCoverage(
            allocationCount: 4,
            totalAllocationCount: 4,
            hospitalCount: 1,
            dispatchAreaCount: 1,
            stateCount: 1,
            firstAt: new \DateTimeImmutable('2024-01-01'),
            lastAt: new \DateTimeImmutable('2025-12-31'),
            years: [
                ['year' => 2024, 'count' => 1],
                ['year' => 2025, 'count' => 3],
            ],
            suppressed: false,
        );

        $rows = $coverage->yearHeatmap(currentYear: 2024);

        self::assertCount(3, $rows);
        self::assertFalse($rows[1][4]['future']); // 2024
        self::assertSame(1, $rows[1][4]['count']);
        self::assertTrue($rows[2][0]['future']); // 2025
        self::assertSame(0, $rows[2][0]['count']);
        self::assertTrue($rows[2][4]['future']); // 2029
    }

    public function testYearHeatmapIsEmptyWhenSuppressedOrWithoutYears(): void
    {
        $suppressed = new CatalogCoverage(
            allocationCount: 2,
            totalAllocationCount: 100,
            hospitalCount: 1,
            dispatchAreaCount: 1,
            stateCount: 1,
            firstAt: null,
            lastAt: null,
            years: [['year' => 2020, 'count' => 2]],
            suppressed: true,
        );

        self::assertSame([], $suppressed->yearHeatmap());
        self::assertSame([], CatalogCoverage::empty()->yearHeatmap());
    }
}
