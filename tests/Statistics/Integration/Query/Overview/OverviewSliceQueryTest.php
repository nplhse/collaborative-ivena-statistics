<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\Infrastructure\Query\Overview\OverviewSliceQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OverviewSliceQueryTest extends KernelTestCase
{
    use Factories;

    public function testReturnsAllTimeMonthlyRowsWithScopedHeatmaps(): void
    {
        self::bootKernel();

        [$hospital, $import, $state, $dispatchArea] = $this->seedHospitalWithImport();

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:30:00'),
        ]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2024-01-10 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-01-10 08:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Month,
            2025,
            6,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, [$hospital->getId()]);

        $slice = self::getContainer()->get(OverviewSliceQuery::class)($criteria);

        self::assertSame(
            [
                ['year' => 2024, 'month' => 1, 'count' => 1],
                ['year' => 2025, 'month' => 6, 'count' => 1],
            ],
            $slice->monthlyRows,
        );
        self::assertSame(1, $this->sumHeatmapCounts($slice->dayTimeHeatmapCells));
        self::assertSame(1, $this->sumHeatmapCounts($slice->shiftHeatmapCells));
        self::assertSame(1, array_sum($slice->transportTimeBucketCounts));
    }

    public function testAllTimePeriodUsesSingleSlice(): void
    {
        self::bootKernel();

        [$hospital, $import, $state, $dispatchArea] = $this->seedHospitalWithImport();

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2025-06-15 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-15 10:30:00'),
        ]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2024-01-10 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2024-01-10 08:20:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::AllTime,
        );
        $bounds = StatisticsPeriodResolver::resolve($filter);
        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, [$hospital->getId()]);

        $slice = self::getContainer()->get(OverviewSliceQuery::class)($criteria);

        self::assertSame(
            [
                ['year' => 2024, 'month' => 1, 'count' => 1],
                ['year' => 2025, 'month' => 6, 'count' => 1],
            ],
            $slice->monthlyRows,
        );
        self::assertSame(2, $this->sumHeatmapCounts($slice->dayTimeHeatmapCells));
        self::assertSame(2, array_sum($slice->transportTimeBucketCounts));
    }

    /**
     * @return array{0: object, 1: object, 2: object, 3: object}
     */
    private function seedHospitalWithImport(): array
    {
        $user = UserFactory::createOne(['username' => 'overview-slice-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewSliceState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewSliceDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewSliceHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewSliceSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewSliceDept']);
        AssignmentFactory::createOne(['name' => 'OverviewSliceAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewSliceRaw', 'code' => 912_351]);
        IndicationNormalizedFactory::createOne(['name' => 'OverviewSliceNorm']);

        $import = ImportFactory::createOne(['name' => 'OverviewSliceImport', 'hospital' => $hospital, 'createdBy' => $user]);

        return [$hospital, $import, $state, $dispatchArea];
    }

    /**
     * @param list<array{count:int}> $cells
     */
    private function sumHeatmapCounts(array $cells): int
    {
        return array_sum(array_column($cells, 'count'));
    }
}
