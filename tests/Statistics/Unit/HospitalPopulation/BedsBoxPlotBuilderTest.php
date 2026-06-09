<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\BedsBoxPlotBuilder;
use App\Statistics\HospitalPopulation\Application\DescriptiveStatisticsCalculator;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use PHPUnit\Framework\TestCase;

final class BedsBoxPlotBuilderTest extends TestCase
{
    private BedsBoxPlotBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BedsBoxPlotBuilder(new DescriptiveStatisticsCalculator());
    }

    public function testBuildsSeparateCareLevelAndLocationBreakdowns(): void
    {
        $snapshots = [
            $this->snapshot(1, HospitalTier::BASIC, HospitalLocation::URBAN, 100, true),
            $this->snapshot(2, HospitalTier::FULL, HospitalLocation::RURAL, 300, false),
            $this->snapshot(3, HospitalTier::FULL, HospitalLocation::RURAL, 500, true),
        ];

        $breakdown = $this->builder->build($snapshots);

        self::assertCount(3, $breakdown->byCareLevel);
        self::assertCount(3, $breakdown->byLocation);

        self::assertSame('Basic', $breakdown->byCareLevel[0]->label);
        self::assertSame(1, $breakdown->byCareLevel[0]->population->count);
        self::assertSame(100, $breakdown->byCareLevel[0]->population->minimum);

        $fullCareLevel = $breakdown->byCareLevel[2];
        self::assertSame('Full', $fullCareLevel->label);
        self::assertSame(2, $fullCareLevel->population->count);
        self::assertSame(300, $fullCareLevel->population->minimum);
        self::assertSame(500, $fullCareLevel->population->maximum);
        self::assertSame(1, $fullCareLevel->participants->count);

        $ruralLocation = $breakdown->byLocation[2];
        self::assertSame('Rural', $ruralLocation->label);
        self::assertSame(2, $ruralLocation->population->count);
        self::assertSame(500, $ruralLocation->participants->minimum);
    }

    public function testOmitsUnknownCareLevelWhenSnapshotHasNoTier(): void
    {
        $snapshots = [
            $this->snapshot(1, null, HospitalLocation::MIXED, 150, false),
        ];

        $breakdown = $this->builder->build($snapshots);

        self::assertCount(3, $breakdown->byCareLevel);
        self::assertSame(0, $breakdown->byCareLevel[0]->population->count);
        self::assertSame(0, $breakdown->byCareLevel[1]->population->count);
        self::assertSame(0, $breakdown->byCareLevel[2]->population->count);

        self::assertSame('Mixed', $breakdown->byLocation[1]->label);
        self::assertSame(1, $breakdown->byLocation[1]->population->count);
    }

    private function snapshot(
        int $id,
        ?HospitalTier $careLevel,
        HospitalLocation $location,
        int $beds,
        bool $isParticipating,
    ): HospitalPopulationSnapshot {
        return new HospitalPopulationSnapshot(
            id: $id,
            name: 'Hospital '.$id,
            stateId: 1,
            stateName: 'Example State',
            dispatchAreaId: 10,
            dispatchAreaName: 'Example Area',
            latitude: 50.1,
            longitude: 8.6,
            beds: $beds,
            size: HospitalSize::SMALL,
            careLevel: $careLevel,
            urbanity: $location,
            hasAllocations: $isParticipating,
            isParticipating: $isParticipating,
            allocationCount: $isParticipating ? 10 : 0,
        );
    }
}
