<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\ClosureAnalytics;

use App\Allocation\Domain\Enum\AllocationUrgency;
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
use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeQuality;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReadModel;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeSeriesScope;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Tests\Statistics\Support\RebuildsClosureAnalysis;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureVolumeProjectionRebuildTest extends KernelTestCase
{
    use Factories;
    use RebuildsClosureAnalysis;

    public function testRebuildIsRepeatableAndUnitesOverlappingClosuresWithoutForeignLeakage(): void
    {
        self::bootKernel();
        $seed = $this->seedComparableTuesday();
        $connection = self::getContainer()->get(Connection::class);
        $first = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'overlap-a');
        $second = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'overlap-b');
        $neighbor = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 12:00:00', '2026-06-02 13:00:00', 'neighbor');
        $foreign = $this->insertClosure($connection, $seed, $seed['foreignDepartmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'foreign', $seed['foreignHospitalId'], $seed['foreignImportId']);

        $this->rebuildAllocations($seed['importId'], $seed['foreignImportId']);
        $this->rebuildClosureAnalysis();
        $volume = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $firstEvent = $this->eventIdForClosure($connection, $first);
        $foreignEvent = $this->eventIdForClosure($connection, $foreign);
        $volume->rebuild();
        $rows = (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour');
        $volume->rebuild();

        self::assertSame($rows, (int) $connection->fetchOne('SELECT COUNT(*) FROM closure_volume_hour'));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM closure_volume_hour GROUP BY event_id, scope, speciality_id, department_id, urgency_code, stratum, bucket_start HAVING COUNT(*) > 1) duplicated',
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM closure_volume_hour WHERE event_id = :id AND scope = :scope AND in_closure = TRUE',
            ['id' => $firstEvent, 'scope' => 'speciality'],
        ));
        self::assertGreaterThan(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM closure_volume_hour WHERE event_id = :id',
            ['id' => $foreignEvent],
        ));

        $readModel = self::getContainer()->get(ClosureVolumeReadModel::class);
        $period = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-06-02 10:30:00'),
            new \DateTimeImmutable('2026-06-02 11:00:00'),
        );
        $clipped = $readModel->burden($this->criteria([$seed['hospitalId']], $period), ClosureVolumeStratum::Sk1);
        self::assertTrue($clipped->built);
        self::assertEqualsWithDelta(0.5, $clipped->observedArea ?? -1, 0.001);
        self::assertEqualsWithDelta(0.5, $clipped->expectedArea ?? -1, 0.001);
        self::assertEqualsWithDelta(0.0, $clipped->absoluteDeviation ?? -1, 0.001);

        $foreignOnly = $readModel->burden($this->criteria([$seed['foreignHospitalId']], $period), ClosureVolumeStratum::Sk1);
        self::assertEqualsWithDelta(2.5, $foreignOnly->observedArea ?? -1, 0.001);
        $empty = $readModel->burden($this->criteria([]), ClosureVolumeStratum::Sk1);
        self::assertFalse($empty->built);
        self::assertNull($empty->observedArea);

        $detail = $readModel->detail($this->criteria([$seed['hospitalId']]), $firstEvent, $seed['hospitalId'], ClosureVolumeStratum::Sk1);
        self::assertTrue($detail->applicable);
        self::assertNotNull($detail->during);
        self::assertEqualsWithDelta(1.0, $detail->during->observedArea ?? -1, 0.001);
        self::assertEqualsWithDelta(1.0, $detail->during->expectedArea ?? -1, 0.001);
        self::assertFalse($detail->during->influenced);
        $windows = [];
        foreach ($detail->windows as $window) {
            $windows[$window->kind] = $window;
        }
        self::assertFalse($windows['post_1']->influenced);
        self::assertTrue($windows['post_3']->influenced);
        self::assertSame('post_1', $detail->comparison['post'] ?? null);
        self::assertNotNull($detail->comparison);
        $notAffected = $readModel->detail($this->criteria([$seed['hospitalId']]), $firstEvent, $seed['hospitalId'], ClosureVolumeStratum::Sk2);
        self::assertFalse($notAffected->applicable);
        self::assertNotSame($neighbor, $first);
        self::assertNotSame($second, $first);
    }

    public function testKnownClosureHoursLeaveTheReferenceAndLaterAssignmentsChangeIt(): void
    {
        self::bootKernel();
        $seed = $this->seedComparableTuesday();
        $connection = self::getContainer()->get(Connection::class);
        $closure = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'target');
        $this->rebuildAllocations($seed['importId']);
        $this->rebuildClosureAnalysis();
        $volume = self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class);
        $eventId = $this->eventIdForClosure($connection, $closure);
        $volume->rebuild();

        $before = $connection->fetchAssociative(
            "SELECT reference_mode, reference_slot_count, expected_area FROM closure_volume_hour WHERE event_id = :id AND scope = 'speciality' AND in_closure = TRUE AND stratum = :stratum AND urgency_code = 1",
            ['id' => $eventId, 'stratum' => 'base'],
        );
        self::assertIsArray($before);
        self::assertSame('weekday_hour', $before['reference_mode']);
        self::assertSame(4, (int) $before['reference_slot_count']);
        self::assertEqualsWithDelta(1.0, (float) $before['expected_area'], 0.001);

        $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-05-12 10:00:00', '2026-05-12 11:00:00', 'blocked-reference');
        $volume->rebuild();
        $blocked = $connection->fetchAssociative(
            "SELECT reference_mode, expected_area FROM closure_volume_hour WHERE event_id = :id AND scope = 'speciality' AND in_closure = TRUE AND stratum = :stratum AND urgency_code = 1",
            ['id' => $eventId, 'stratum' => 'base'],
        );
        self::assertIsArray($blocked);
        self::assertSame('day_time_bucket', $blocked['reference_mode']);
        self::assertLessThan(1.0, (float) $blocked['expected_area']);

        AllocationFactory::createOne($this->allocation($seed, $seed['department'], '2026-05-05 10:20:00'));
        $this->rebuildAllocations($seed['importId']);
        $volume->rebuild();
        $changed = $connection->fetchOne(
            "SELECT expected_area FROM closure_volume_hour WHERE event_id = :id AND scope = 'speciality' AND in_closure = TRUE AND stratum = :stratum AND urgency_code = 1",
            ['id' => $eventId, 'stratum' => 'base'],
        );
        self::assertNotEqualsWithDelta((float) $blocked['expected_area'], (float) $changed, 0.0001);
    }

    public function testSeveralHospitalsShareTheSummedDenominator(): void
    {
        self::bootKernel();
        $seed = $this->seedComparableTuesday();
        $connection = self::getContainer()->get(Connection::class);
        $quietDepartment = DepartmentFactory::createOne(['name' => 'Quiet Volume Department']);
        $this->insertClosure($connection, $seed, (int) $seed['departmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'share-a');
        $this->insertClosure($connection, $seed, (int) $quietDepartment->getId(), '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'share-b', $seed['foreignHospitalId'], $seed['foreignImportId']);
        $otherSpeciality = SpecialityFactory::createOne(['name' => 'Other Volume Speciality']);
        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'] as $day) {
            for ($index = 0; $index < 4; ++$index) {
                AllocationFactory::createOne([
                    ...$this->allocation($seed, $seed['foreignDepartment'], $day.' 10:'.(10 + $index).':00', true),
                    'speciality' => $otherSpeciality,
                ]);
            }
        }
        AllocationFactory::createOne($this->allocation($seed, $seed['foreignDepartment'], '2026-06-02 10:15:00', true));

        $this->rebuildAllocations($seed['importId'], $seed['foreignImportId']);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        $period = new StatisticsPeriodBounds(
            new \DateTimeImmutable('2026-06-02 10:00:00'),
            new \DateTimeImmutable('2026-06-02 11:00:00'),
        );
        $readModel = self::getContainer()->get(ClosureVolumeReadModel::class);
        $combined = $readModel->burden($this->criteria([$seed['hospitalId'], $seed['foreignHospitalId']], $period), ClosureVolumeStratum::Sk1);
        $own = $readModel->burden($this->criteria([$seed['hospitalId']], $period), ClosureVolumeStratum::Sk1);
        $other = $readModel->burden($this->criteria([$seed['foreignHospitalId']], $period), ClosureVolumeStratum::Sk1);

        self::assertEqualsWithDelta(1.0, $own->share ?? -1, 0.001);
        self::assertEqualsWithDelta(0.0, $other->share ?? -1, 0.001);
        self::assertEqualsWithDelta(0.2, $combined->share ?? -1, 0.001);
        self::assertNotEqualsWithDelta(0.5, $combined->share ?? -1, 0.001);
    }

    public function testDetailUsesDepartmentScopeWhileSpecialityReferenceIncludesSisterDepartment(): void
    {
        self::bootKernel();
        $seed = $this->seedComparableTuesday();
        $connection = self::getContainer()->get(Connection::class);
        $sisterDepartment = DepartmentFactory::createOne(['name' => 'Sister Volume Department']);
        $closure = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 10:00:00', '2026-06-02 11:00:00', 'sister-scope');
        AllocationFactory::createOne($this->allocation($seed, $sisterDepartment, '2026-06-02 10:16:00'));
        $this->rebuildAllocations($seed['importId']);
        $this->rebuildClosureAnalysis();
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        $eventId = $this->eventIdForClosure($connection, $closure);
        $readModel = self::getContainer()->get(ClosureVolumeReadModel::class);
        $criteria = $this->criteria([$seed['hospitalId']]);
        $departmentView = $readModel->detail($criteria, $eventId, $seed['hospitalId'], ClosureVolumeStratum::Sk1, ClosureVolumeSeriesScope::Department);
        $specialityView = $readModel->detail($criteria, $eventId, $seed['hospitalId'], ClosureVolumeStratum::Sk1, ClosureVolumeSeriesScope::Speciality);

        self::assertTrue($departmentView->applicable);
        self::assertArrayHasKey('referenceAreaRatio', $departmentView->chart);
        self::assertArrayNotHasKey('referenceObserved', $departmentView->chart);
        self::assertArrayNotHasKey('referenceAreaRatio', $specialityView->chart);
        self::assertNotNull($departmentView->during);
        self::assertGreaterThan(0.0, $departmentView->during->observedArea);

        $departmentObserved = (float) $connection->fetchOne(
            "SELECT COALESCE(SUM(observed_area), 0) FROM closure_volume_hour WHERE event_id = :event_id AND scope = 'department' AND in_closure = TRUE AND stratum = 'base'",
            ['event_id' => $eventId],
        );
        $specialityObserved = (float) $connection->fetchOne(
            "SELECT COALESCE(SUM(observed_area), 0) FROM closure_volume_hour WHERE event_id = :event_id AND scope = 'speciality' AND in_closure = TRUE AND stratum = 'base'",
            ['event_id' => $eventId],
        );
        self::assertGreaterThan($departmentObserved, $specialityObserved);
    }

    public function testOngoingClosureHasNoFollowUpAndDoesNotTreatTheFutureAsZero(): void
    {
        self::bootKernel();
        $seed = $this->seedComparableTuesday();
        $connection = self::getContainer()->get(Connection::class);
        $closure = $this->insertClosure($connection, $seed, $seed['departmentId'], '2026-06-02 10:00:00', '2099-01-01 00:00:00', 'ongoing');
        $this->rebuildAllocations($seed['importId']);
        self::getContainer()->get(ClosureVolumeProjectionRebuildInterface::class)->rebuild();

        $detail = self::getContainer()->get(ClosureVolumeReadModel::class)->detail(
            $this->criteria([$seed['hospitalId']]),
            $this->eventIdForClosure($connection, $closure),
            $seed['hospitalId'],
            ClosureVolumeStratum::Sk1,
        );
        self::assertTrue($detail->ongoing);
        self::assertNotNull($detail->during);
        self::assertFalse($detail->during->complete);
        self::assertSame(ClosureVolumeQuality::Incomplete, $detail->during->quality);
        $post = null;
        foreach ($detail->windows as $window) {
            if ('post_1' === $window->kind) {
                $post = $window;
            }
        }
        self::assertNotNull($post);
        self::assertSame(ClosureVolumeQuality::Ongoing, $post->quality);
        self::assertNull($post->observedArea);
        self::assertNull($detail->comparison);
    }

    /**
     * @return array{
     *     hospitalId: int,
     *     departmentId: int,
     *     importId: int,
     *     specialityId: int,
     *     foreignHospitalId: int,
     *     foreignDepartmentId: int,
     *     foreignImportId: int,
     *     hospital: object,
     *     department: object,
     *     import: object,
     *     foreignHospital: object,
     *     foreignDepartment: object,
     *     foreignImport: object,
     *     state: object,
     *     dispatch: object,
     *     speciality: object
     * }
     */
    private function seedComparableTuesday(): array
    {
        $user = UserFactory::createOne(['username' => 'closure-volume-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Volume Hospital', 'state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user]);
        $foreignHospital = HospitalFactory::createOne(['name' => 'Foreign Volume Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Volume Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Volume Department']);
        $foreignDepartment = DepartmentFactory::createOne(['name' => 'Foreign Volume Department']);
        AssignmentFactory::createOne(['name' => 'Volume Assignment']);
        IndicationRawFactory::createOne(['name' => 'Volume Raw', 'code' => random_int(800, 900)]);
        IndicationNormalizedFactory::createOne(['name' => 'Volume Indication']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $foreignImport = ImportFactory::createOne(['hospital' => $foreignHospital, 'createdBy' => $user]);

        $seed = [
            'hospitalId' => (int) $hospital->getId(),
            'departmentId' => (int) $department->getId(),
            'importId' => (int) $import->getId(),
            'specialityId' => (int) $speciality->getId(),
            'foreignHospitalId' => (int) $foreignHospital->getId(),
            'foreignDepartmentId' => (int) $foreignDepartment->getId(),
            'foreignImportId' => (int) $foreignImport->getId(),
            'hospital' => $hospital,
            'department' => $department,
            'import' => $import,
            'foreignHospital' => $foreignHospital,
            'foreignDepartment' => $foreignDepartment,
            'foreignImport' => $foreignImport,
            'state' => $state,
            'dispatch' => $dispatch,
            'speciality' => $speciality,
        ];
        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-26'] as $day) {
            AllocationFactory::createOne($this->allocation($seed, $department, $day.' 10:10:00'));
        }
        AllocationFactory::createOne($this->allocation($seed, $department, '2026-06-02 10:15:00'));
        for ($index = 0; $index < 5; ++$index) {
            AllocationFactory::createOne($this->allocation($seed, $foreignDepartment, '2026-06-02 10:2'.$index.':00', true));
        }

        return $seed;
    }

    /**
     * @param array<string, mixed> $seed
     *
     * @return array<string, mixed>
     */
    private function allocation(array $seed, object $department, string $createdAt, bool $foreign = false): array
    {
        return [
            'import' => $foreign ? $seed['foreignImport'] : $seed['import'],
            'hospital' => $foreign ? $seed['foreignHospital'] : $seed['hospital'],
            'state' => $seed['state'],
            'dispatchArea' => $seed['dispatch'],
            'speciality' => $seed['speciality'],
            'department' => $department,
            'createdAt' => new \DateTimeImmutable($createdAt),
            'arrivalAt' => new \DateTimeImmutable($createdAt)->modify('+20 minutes'),
            'urgency' => AllocationUrgency::EMERGENCY,
            'requiresResus' => false,
            'requiresCathlab' => false,
        ];
    }

    /**
     * @param array<string, mixed> $seed
     */
    private function insertClosure(
        Connection $connection,
        array $seed,
        int $departmentId,
        string $startsAt,
        string $endsAt,
        string $groupId,
        ?int $hospitalId = null,
        ?int $importId = null,
    ): int {
        $connection->insert('closure_interval', [
            'hospital_id' => $hospitalId ?? $seed['hospitalId'],
            'import_id' => $importId ?? $seed['importId'],
            'speciality_id' => $seed['specialityId'],
            'department_id' => $departmentId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => 'emergency',
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => 'volume unit',
            'source_group_id' => $groupId,
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);

        $id = (int) $connection->lastInsertId();
        $connection->executeStatement(
            "UPDATE import SET status = 'Completed' WHERE id = :id",
            ['id' => $importId ?? $seed['importId']],
        );

        return $id;
    }

    private function eventIdForClosure(Connection $connection, int $closureIntervalId): int
    {
        return (int) $connection->fetchOne(
            'SELECT ai.event_id FROM closure_analysis_source s INNER JOIN closure_analysis_interval ai ON ai.id = s.analysis_interval_id WHERE s.closure_interval_id = :id',
            ['id' => $closureIntervalId],
        );
    }

    private function rebuildAllocations(int ...$importIds): void
    {
        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ($importIds as $importId) {
            $rebuilder->rebuildForImport($importId);
        }
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function criteria(array $hospitalIds, ?StatisticsPeriodBounds $period = null): ClosureAnalyticsCriteria
    {
        return new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria($hospitalIds),
            $period ?? new StatisticsPeriodBounds(null, null),
            TimeSeriesGrain::Day,
            new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::AllTime),
        );
    }
}
