<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\AllocationBasisSummaryCalculator;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use PHPUnit\Framework\TestCase;

final class AllocationBasisSummaryCalculatorTest extends TestCase
{
    public function testSummarizesAllocationCounts(): void
    {
        $calculator = new AllocationBasisSummaryCalculator(new DescriptiveStatisticsCalculator());
        $snapshots = [
            new HospitalPopulationSnapshot(1, 'A', 1, 'State A', 1, 'Area A', null, null, 100, HospitalSize::SMALL, HospitalTier::BASIC, HospitalLocation::URBAN, true, true, 10),
            new HospitalPopulationSnapshot(2, 'B', 1, 'State A', 1, 'Area A', null, null, 200, HospitalSize::LARGE, HospitalTier::FULL, HospitalLocation::RURAL, true, true, 30),
            new HospitalPopulationSnapshot(3, 'C', 2, 'State B', 2, 'Area B', null, null, 150, HospitalSize::MEDIUM, HospitalTier::EXTENDED, HospitalLocation::MIXED, false, false, 0),
        ];

        $summary = $calculator->calculate($snapshots);

        self::assertCount(3, $summary->bySize);
        self::assertCount(3, $summary->byTier);
        self::assertCount(3, $summary->byLocation);

        self::assertSame(10, $summary->bySize[0]->totalAllocations);
        self::assertSame(25.0, $summary->bySize[0]->sharePercent);
        self::assertSame(1, $summary->bySize[0]->hospitalCount);
        self::assertSame(0, $summary->bySize[1]->totalAllocations);
        self::assertSame(0.0, $summary->bySize[1]->sharePercent);

        self::assertSame(10, $summary->byTier[0]->totalAllocations);
        self::assertSame(30, $summary->byTier[2]->totalAllocations);
        self::assertSame(0, $summary->byTier[1]->totalAllocations);

        self::assertSame(10, $summary->byLocation[0]->totalAllocations);
        self::assertSame(0, $summary->byLocation[1]->totalAllocations);
        self::assertSame(30, $summary->byLocation[2]->totalAllocations);
    }

    public function testCrossTablesIncludeEmptyCellsAndExcludeUnknownTier(): void
    {
        $calculator = new AllocationBasisSummaryCalculator(new DescriptiveStatisticsCalculator());
        $snapshots = [
            new HospitalPopulationSnapshot(1, 'A', 1, 'State A', 1, 'Area A', null, null, 100, HospitalSize::SMALL, null, HospitalLocation::URBAN, true, true, 12),
            new HospitalPopulationSnapshot(2, 'B', 1, 'State A', 1, 'Area A', null, null, 200, HospitalSize::LARGE, HospitalTier::FULL, HospitalLocation::RURAL, true, true, 8),
        ];

        $summary = $calculator->calculate($snapshots);

        self::assertSame(0, $summary->byTier[0]->totalAllocations);
        self::assertSame(8, $summary->byTier[2]->totalAllocations);
        self::assertSame(40.0, $summary->byTier[2]->sharePercent);

        $fullRuralCell = $summary->locationByTierCrossTable->rows[2]->cells[2];
        self::assertSame(8, $fullRuralCell->totalAllocations);
        self::assertSame(1, $fullRuralCell->hospitalCount);
        self::assertSame(8.0, $fullRuralCell->meanPerHospital);
        self::assertSame(40.0, $fullRuralCell->sharePercent);

        $basicUrbanCell = $summary->locationByTierCrossTable->rows[0]->cells[0];
        self::assertSame(0, $basicUrbanCell->totalAllocations);
        self::assertSame(0, $basicUrbanCell->hospitalCount);
        self::assertNull($basicUrbanCell->meanPerHospital);

        $largeFullCell = $summary->sizeByTierCrossTable->rows[2]->cells[2];
        self::assertSame(8, $largeFullCell->totalAllocations);
    }
}
