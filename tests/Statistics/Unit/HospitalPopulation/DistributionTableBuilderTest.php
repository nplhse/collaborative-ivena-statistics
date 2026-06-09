<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\HospitalPopulation;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\CoverageCalculator;
use App\Statistics\HospitalPopulation\Application\DistributionTableBuilder;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use App\Statistics\HospitalPopulation\Application\RepresentativityCalculator;
use PHPUnit\Framework\TestCase;

final class DistributionTableBuilderTest extends TestCase
{
    private DistributionTableBuilder $builder;

    protected function setUp(): void
    {
        $coverageCalculator = new CoverageCalculator();
        $this->builder = new DistributionTableBuilder(
            $coverageCalculator,
            new RepresentativityCalculator($coverageCalculator),
        );
    }

    public function testBuildsSizeDistribution(): void
    {
        $snapshots = [
            $this->snapshot(1, HospitalSize::SMALL, HospitalTier::BASIC, true),
            $this->snapshot(2, HospitalSize::SMALL, HospitalTier::BASIC, false),
            $this->snapshot(3, HospitalSize::LARGE, HospitalTier::FULL, true),
        ];

        $rows = $this->builder->buildCategoryTable(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
            static fn (string $key): string => $key,
            ['Small', 'Medium', 'Large'],
        );

        self::assertSame('Small', $rows[0]->label);
        self::assertSame(2, $rows[0]->population);
        self::assertSame(1, $rows[0]->participants);
        self::assertSame(0.5, $rows[0]->coverage);
        self::assertSame(1, $rows[2]->population);
        self::assertSame(1.0, $rows[2]->coverage);
    }

    public function testBuildsRegionalCoverageTable(): void
    {
        $snapshots = [
            $this->snapshot(1, HospitalSize::SMALL, HospitalTier::BASIC, true, stateId: 1, stateName: 'State A', dispatchAreaId: 1, dispatchAreaName: 'Alpha'),
            $this->snapshot(2, HospitalSize::SMALL, HospitalTier::BASIC, false, stateId: 1, stateName: 'State A', dispatchAreaId: 1, dispatchAreaName: 'Alpha'),
            $this->snapshot(3, HospitalSize::SMALL, HospitalTier::BASIC, false, stateId: 1, stateName: 'State A', dispatchAreaId: 2, dispatchAreaName: 'Beta'),
        ];

        $rows = $this->builder->buildRegionalCoverageTable($snapshots);

        self::assertCount(2, $rows);
        self::assertSame('Alpha', $rows[0]->dispatchAreaName);
        self::assertSame(2, $rows[0]->population);
        self::assertSame(1, $rows[0]->participants);
        self::assertSame(0.5, $rows[0]->coverage);
        self::assertSame('Beta', $rows[1]->dispatchAreaName);
        self::assertSame(1, $rows[1]->population);
        self::assertSame(0, $rows[1]->participants);
        self::assertSame(0.0, $rows[1]->coverage);
    }

    public function testBuildsSizeByTierCrossTable(): void
    {
        $snapshots = [
            $this->snapshot(1, HospitalSize::SMALL, HospitalTier::BASIC, true),
            $this->snapshot(2, HospitalSize::SMALL, HospitalTier::BASIC, false),
            $this->snapshot(3, HospitalSize::LARGE, HospitalTier::FULL, true),
        ];

        $crossTable = $this->builder->buildCrossTable(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
            ['Basic', 'Extended', 'Full'],
            ['Small', 'Medium', 'Large'],
            static fn (string $key): string => $key,
        );

        self::assertCount(3, $crossTable->columns);
        self::assertCount(3, $crossTable->rows);
        self::assertSame('Basic', $crossTable->rows[0]->label);
        self::assertSame(2, $crossTable->rows[0]->cells[0]->population);
        self::assertSame(1, $crossTable->rows[0]->cells[0]->participants);
        self::assertSame(0.5, $crossTable->rows[0]->cells[0]->coverage);
        self::assertSame('Full', $crossTable->rows[2]->label);
        self::assertSame(1, $crossTable->rows[2]->cells[2]->population);
        self::assertSame(1.0, $crossTable->rows[2]->cells[2]->coverage);
    }

    public function testCrossTableOmitsSnapshotsWithoutTier(): void
    {
        $snapshots = [
            $this->snapshot(1, HospitalSize::SMALL, null, false),
        ];

        $crossTable = $this->builder->buildCrossTable(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
            static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
            ['Basic', 'Extended', 'Full'],
            ['Small', 'Medium', 'Large'],
            static fn (string $key): string => $key,
        );

        self::assertSame(0, $crossTable->rows[0]->cells[0]->population);
    }

    private function snapshot(
        int $id,
        HospitalSize $size,
        ?HospitalTier $tier,
        bool $isParticipating,
        int $stateId = 1,
        string $stateName = 'Example State',
        int $dispatchAreaId = 10,
        string $dispatchAreaName = 'Example Area',
    ): HospitalPopulationSnapshot {
        return new HospitalPopulationSnapshot(
            id: $id,
            name: 'Hospital '.$id,
            stateId: $stateId,
            stateName: $stateName,
            dispatchAreaId: $dispatchAreaId,
            dispatchAreaName: $dispatchAreaName,
            latitude: 50.1,
            longitude: 8.6,
            beds: 100,
            size: $size,
            careLevel: $tier,
            urbanity: HospitalLocation::URBAN,
            hasAllocations: $isParticipating,
            isParticipating: $isParticipating,
            allocationCount: $isParticipating ? 10 : 0,
        );
    }
}
