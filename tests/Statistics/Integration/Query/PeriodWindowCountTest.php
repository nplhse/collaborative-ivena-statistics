<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1017: `last_12_months` and `all_time` select different populations.
 *
 * Now = 2026-11-11, so the rolling window starts 2025-12-01 00:00 (no upper bound).
 * Allocations: 2019-03-10 and 2025-11-30 23:59 (before the window), 2025-12-01 00:00 and 2026-11-10 (inside).
 * Expected: last_12_months = 2, all_time = 4.
 */
#[ResetDatabase]
final class PeriodWindowCountTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testLast12MonthsCountsOnlyTheRollingWindowAndAllTimeCountsTheWholeHistory(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-11 21:40:00'));

        $hospitalId = $this->seedAllocations([
            '2019-03-10 10:00:00',
            '2025-11-30 23:59:00',
            '2025-12-01 00:00:00',
            '2026-11-10 08:00:00',
        ]);

        self::assertSame(2, $this->countAllocations(StatisticsFilterPeriod::Last12Months, $hospitalId));
        self::assertSame(4, $this->countAllocations(StatisticsFilterPeriod::AllTime, $hospitalId));
    }

    private function countAllocations(StatisticsFilterPeriod $period, int $hospitalId): int
    {
        $bounds = StatisticsPeriodResolver::resolve(
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospitalId, null, $period),
        );

        return self::getContainer()->get(ProjectionTimeSeriesQuery::class)
            ->countCreatedInPeriod($bounds->from, $bounds->toExclusive, [$hospitalId]);
    }

    /**
     * @param list<string> $createdAt
     */
    private function seedAllocations(array $createdAt): int
    {
        $user = UserFactory::createOne(['username' => 'period-window-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'PeriodWindowState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'PeriodWindowDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'PeriodWindowHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'PeriodWindowSpec']);
        DepartmentFactory::createOne(['name' => 'PeriodWindowDept']);
        AssignmentFactory::createOne(['name' => 'PeriodWindowAssign']);
        OccasionFactory::createOne(['name' => 'PeriodWindowOcc']);
        SecondaryTransportFactory::createOne(['name' => 'PeriodWindowSec']);
        InfectionFactory::createOne(['name' => 'PeriodWindowInf']);
        IndicationRawFactory::createOne(['name' => 'PeriodWindowRaw', 'code' => 917_017]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'PeriodWindowNorm']);

        $import = ImportFactory::createOne(['name' => 'PeriodWindowImport', 'hospital' => $hospital, 'createdBy' => $user]);

        foreach ($createdAt as $value) {
            $created = new \DateTimeImmutable($value);
            AllocationFactory::createOne([
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationNormalized' => $indicationNormalized,
                'createdAt' => $created,
                'arrivalAt' => $created->modify('+30 minutes'),
            ]);
        }

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        return $hospital->getId();
    }
}
