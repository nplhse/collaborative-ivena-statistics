<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationLoadCalculator;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationLoadService;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureDurationLoadQueryTest extends KernelTestCase
{
    use Factories;

    public function testClipsIntervalsToThePeriodAndDropsOtherHospitals(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'duration-load-clip']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Duration Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $otherHospital = HospitalFactory::createOne(['name' => 'Hidden Duration Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Duration Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Duration Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $otherImport = ImportFactory::createOne(['hospital' => $otherHospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-01-01 22:00:00', '2026-01-02 06:00:00', 'clip-group');
        $this->insertInterval($connection, $otherHospital->getId(), $otherImport->getId(), $speciality->getId(), $department->getId(), '2026-01-02 00:00:00', '2026-01-02 02:00:00', 'foreign-group');

        $criteria = $this->criteria([$hospital->getId()], new \DateTimeImmutable('2026-01-02 00:00:00'), new \DateTimeImmutable('2026-01-02 03:00:00'));
        $snapshot = self::getContainer()->get(ClosureTemporalQuery::class)->fetchDurationLoad($criteria);

        self::assertCount(1, $snapshot->intervals);
        self::assertSame($hospital->getId(), $snapshot->intervals[0]->hospitalId);
        self::assertSame('Duration Department', $snapshot->intervals[0]->departmentName);
        self::assertSame('Duration Speciality', $snapshot->intervals[0]->specialityName);
        self::assertSame(10_800, $snapshot->intervals[0]->end->getTimestamp() - $snapshot->intervals[0]->start->getTimestamp());
        self::assertNotEmpty($snapshot->observed);

        $empty = self::getContainer()->get(ClosureTemporalQuery::class)->fetchDurationLoad(
            $this->criteria([], new \DateTimeImmutable('2026-01-02 00:00:00'), new \DateTimeImmutable('2026-01-02 03:00:00')),
        );
        self::assertSame([], $empty->intervals);
        self::assertSame([], $empty->observed);
    }

    public function testRunningClosureStopsAtNowAndSameDepartmentIsNotDuplicated(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'duration-load-now']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Running Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Running Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Running Department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => 'Parallel Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-05-01 10:00:00', '2099-01-01 10:00:00', 'running-group', 'emergency', 'no_bed_capacity');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-05-01 10:00:00', '2099-01-01 10:00:00', 'running-group', 'inpatient', 'technical_fault');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $otherDepartment->getId(), '2026-05-01 10:00:00', '2026-05-01 12:00:00', 'running-group', 'emergency', 'no_bed_capacity');

        $load = $this->serviceAt('2026-06-01 12:00:00')->build(
            $this->criteria([$hospital->getId()], null, new \DateTimeImmutable('2099-01-02 00:00:00')),
        );

        self::assertSame(1, $load->eventCount);
        self::assertSame(2_685_600, $load->maximumSeconds);
        self::assertSame(2_685_600, $load->evaluableSeconds);
        self::assertSame(7_200, $load->multipleDepartmentsSeconds);
        self::assertSame($load->evaluableSeconds - 7_200, $load->singleDepartmentSeconds);
        self::assertSame(0, $load->noneSeconds);
        self::assertSame($load->evaluableSeconds, $load->noneSeconds + $load->singleDepartmentSeconds + $load->multipleDepartmentsSeconds);
        self::assertCount(1, $load->specialities);
        self::assertSame('Running Speciality', $load->specialities[0]->label);
        self::assertSame(1, $load->specialities[0]->count);
        $reasons = [];
        foreach ($load->reasons as $group) {
            $reasons[$group->key] = $group->count;
        }
        self::assertSame(1, $reasons['no_bed_capacity']);
        self::assertSame(1, $reasons['technical_fault']);
    }

    private function serviceAt(string $berlinWallClock): ClosureDurationLoadService
    {
        return new ClosureDurationLoadService(
            self::getContainer()->get(ClosureTemporalQuery::class),
            self::getContainer()->get(ClosureDurationLoadCalculator::class),
            new readonly class($berlinWallClock) implements ClockInterface {
                public function __construct(private string $wallClock)
                {
                }

                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable($this->wallClock, new \DateTimeZone('Europe/Berlin'));
                }
            },
        );
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function criteria(array $hospitalIds, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ClosureAnalyticsCriteria
    {
        return new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria($hospitalIds),
            new StatisticsPeriodBounds($from, $to),
            TimeSeriesGrain::Month,
            new StatisticsFilter(
                StatisticsFilterScope::Hospital,
                $hospitalIds[0] ?? null,
                null,
                StatisticsFilterPeriod::AllTime,
            ),
        );
    }

    private function insertInterval(
        Connection $connection,
        int $hospitalId,
        int $importId,
        int $specialityId,
        int $departmentId,
        string $startsAt,
        string $endsAt,
        string $groupId,
        string $careLevel = 'emergency',
        string $reason = 'no_bed_capacity',
    ): void {
        $connection->insert('closure_interval', [
            'hospital_id' => $hospitalId,
            'import_id' => $importId,
            'speciality_id' => $specialityId,
            'department_id' => $departmentId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => $careLevel,
            'reason' => $reason,
            'facility_kind' => 'clinic',
            'closure_unit' => 'Duration unit',
            'source_group_id' => $groupId,
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);
    }
}
