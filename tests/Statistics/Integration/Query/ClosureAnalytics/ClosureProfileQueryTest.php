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
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCoursePoint;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileDepartment;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileView;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureRecurringProfileTableQuery;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureProfileQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureProfileQueryTest extends KernelTestCase
{
    use Factories;

    public function testConfigurationsDurationsSpecialitiesAndPeriodStayDistinct(): void
    {
        self::bootKernel();
        $seed = $this->seedHospital();
        $connection = self::getContainer()->get(Connection::class);
        $short = $this->insertEvent($connection, $seed['hospitalId'], '2026-05-01 10:00:00', '2026-05-01 11:00:00');
        $long = $this->insertEvent($connection, $seed['hospitalId'], '2026-05-02 12:00:00', '2026-05-02 15:00:00');
        $otherDepartment = $this->insertEvent($connection, $seed['hospitalId'], '2026-05-03 10:00:00', '2026-05-03 12:00:00');
        $both = $this->insertEvent($connection, $seed['hospitalId'], '2026-05-04 08:00:00', '2026-05-04 10:00:00');
        $outside = $this->insertEvent($connection, $seed['hospitalId'], '2025-03-01 10:00:00', '2025-03-01 11:00:00');

        $this->insertInterval($connection, $short, $seed, $seed['specialityA'], $seed['departmentA'], '2026-05-01 10:00:00', '2026-05-01 11:00:00', 'unit-a');
        $this->insertInterval($connection, $long, $seed, $seed['specialityA'], $seed['departmentA'], '2026-05-02 12:00:00', '2026-05-02 15:00:00', 'unit-b');
        $this->insertInterval($connection, $otherDepartment, $seed, $seed['specialityA'], $seed['departmentB'], '2026-05-03 10:00:00', '2026-05-03 12:00:00', 'unit-a');
        $this->insertInterval($connection, $both, $seed, $seed['specialityA'], $seed['departmentA'], '2026-05-04 08:00:00', '2026-05-04 10:00:00', 'unit-a');
        $this->insertInterval($connection, $both, $seed, $seed['specialityB'], $seed['departmentB'], '2026-05-04 08:00:00', '2026-05-04 09:00:00', 'unit-a');
        $this->insertInterval($connection, $outside, $seed, $seed['specialityA'], $seed['departmentA'], '2025-03-01 10:00:00', '2025-03-01 11:00:00', 'unit-a');

        $profiles = self::getContainer()->get(ClosureProfileQuery::class);
        $same = $profiles->linksForEvent($short, $seed['hospitalId']);
        $sameOtherUnit = $profiles->linksForEvent($long, $seed['hospitalId']);
        $different = $profiles->linksForEvent($otherDepartment, $seed['hospitalId']);
        $twoSpecialities = $profiles->linksForEvent($both, $seed['hospitalId']);

        self::assertNotNull($same->groupKey);
        self::assertSame($same->groupKey, $sameOtherUnit->groupKey);
        self::assertNotSame($same->groupKey, $different->groupKey);
        self::assertNotSame($same->groupKey, $twoSpecialities->groupKey);
        self::assertCount(2, $twoSpecialities->specialities);

        $criteria = $this->criteria($seed['hospitalId'], '2026-01-01', '2027-01-01');
        $group = $profiles->view($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], (string) $same->groupKey)), ClosureVolumeStratum::All);
        $other = $profiles->view($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], (string) $different->groupKey)), ClosureVolumeStratum::All);
        $specialityA = $profiles->view($criteria->withProfile(ClosureProfileRef::speciality($seed['hospitalId'], $seed['specialityA'])), ClosureVolumeStratum::All);
        $specialityB = $profiles->view($criteria->withProfile(ClosureProfileRef::speciality($seed['hospitalId'], $seed['specialityB'])), ClosureVolumeStratum::All);
        self::assertInstanceOf(ClosureProfileView::class, $group);
        self::assertInstanceOf(ClosureProfileView::class, $other);
        self::assertInstanceOf(ClosureProfileView::class, $specialityA);
        self::assertInstanceOf(ClosureProfileView::class, $specialityB);

        self::assertSame(2, $group->eventCount);
        self::assertTrue($group->showIndividuals);
        self::assertEqualsWithDelta(120.0, (float) $group->medianMinutes, 0.01);
        self::assertEqualsWithDelta(120.0, (float) $group->meanMinutes, 0.01);
        self::assertCount(2, $group->individualMinutes);
        self::assertEqualsWithDelta(60.0, $group->individualMinutes[0], 0.01);
        self::assertEqualsWithDelta(180.0, $group->individualMinutes[1], 0.01);
        self::assertSame(1, $other->eventCount);
        self::assertSame(4, $specialityA->eventCount);
        self::assertSame(1, $specialityB->eventCount);
        self::assertSame(
            $specialityA->eventCount,
            self::getContainer()->get(ClosureEventQuery::class)->countEvents($criteria->withProfile(ClosureProfileRef::speciality($seed['hospitalId'], $seed['specialityA']))),
        );

        $open = $this->department($specialityA, 'Open Profile Department');
        $closed = $this->department($specialityA, 'Closed Profile Department');
        self::assertTrue($open->fromAssignment);
        self::assertFalse($open->fromClosure);
        self::assertTrue($closed->fromClosure);
        self::assertTrue($closed->fromAssignment);
        self::assertSame(4, $this->yearCount($specialityA, 2026));
        self::assertSame(0, $this->yearCount($specialityA, 2025));
        $overview = $profiles->overview($criteria);
        $repeated = null;
        $once = null;
        foreach ($overview->groups as $item) {
            if ($item->key === $same->groupKey) {
                $repeated = $item;
            }
            if ($item->key === $different->groupKey) {
                $once = $item;
            }
        }
        self::assertNotNull($repeated);
        self::assertNotNull($once);
        self::assertSame(2, $repeated->eventCount);
        self::assertStringContainsString('Closed Profile Department', $repeated->title);
        self::assertStringContainsString('SK1', $repeated->title);
        self::assertStringContainsString('No bed capacity', $repeated->title);
        self::assertStringContainsString('Other Profile Department', $once->title);
        self::assertSame($repeated->title, $group->title);
        self::assertFalse($repeated->once());
        self::assertTrue($once->once());
        self::assertLessThan(
            array_search($once, $overview->groups, true),
            array_search($repeated, $overview->groups, true),
        );
    }

    public function testBidirectionalUnitMatchHidesGroupFromOverviewAndEventLinksWithCriteria(): void
    {
        self::bootKernel();
        $seed = $this->seedHospital();
        $connection = self::getContainer()->get(Connection::class);
        $first = $this->insertEvent($connection, $seed['hospitalId'], '2026-07-01 10:00:00', '2026-07-01 11:00:00');
        $second = $this->insertEvent($connection, $seed['hospitalId'], '2026-07-02 10:00:00', '2026-07-02 11:00:00');
        $mixedUnit = $this->insertEvent($connection, $seed['hospitalId'], '2026-07-03 10:00:00', '2026-07-03 11:00:00');

        $this->insertInterval($connection, $first, $seed, $seed['specialityA'], $seed['departmentA'], '2026-07-01 10:00:00', '2026-07-01 11:00:00', 'unit-only');
        $this->insertInterval($connection, $second, $seed, $seed['specialityA'], $seed['departmentA'], '2026-07-02 10:00:00', '2026-07-02 11:00:00', 'unit-only');
        $this->insertInterval($connection, $mixedUnit, $seed, $seed['specialityA'], $seed['departmentA'], '2026-07-03 10:00:00', '2026-07-03 11:00:00', 'unit-only');
        $this->insertInterval($connection, $mixedUnit, $seed, $seed['specialityB'], $seed['departmentB'], '2026-07-03 10:00:00', '2026-07-03 10:30:00', 'unit-other');

        $profiles = self::getContainer()->get(ClosureProfileQuery::class);
        $criteria = $this->criteria($seed['hospitalId'], '2026-01-01', '2027-01-01');
        $homogeneousKey = $profiles->linksForEvent($first, $seed['hospitalId'])->groupKey;
        self::assertNotNull($homogeneousKey);
        self::assertSame($homogeneousKey, $profiles->linksForEvent($second, $seed['hospitalId'])->groupKey);
        self::assertNull($profiles->linksForEvent($first, $seed['hospitalId'], $criteria)->groupKey);
        self::assertNotNull($profiles->linksForEvent($first, $seed['hospitalId'])->groupKey);

        $overview = $profiles->overview($criteria);
        foreach ($overview->groups as $item) {
            self::assertNotSame($homogeneousKey, $item->key);
        }

        $otherKey = $profiles->linksForEvent($mixedUnit, $seed['hospitalId'])->groupKey;
        self::assertNotSame($homogeneousKey, $otherKey);
        $mixedListed = false;
        foreach ($overview->groups as $item) {
            if ($item->key === $otherKey) {
                $mixedListed = true;
            }
        }
        self::assertTrue($mixedListed);
    }

    public function testRecurringGroupPageFiltersRedundantRowsAndSearch(): void
    {
        self::bootKernel();
        $seed = $this->seedHospital();
        $connection = self::getContainer()->get(Connection::class);
        $first = $this->insertEvent($connection, $seed['hospitalId'], '2026-08-01 10:00:00', '2026-08-01 11:00:00');
        $second = $this->insertEvent($connection, $seed['hospitalId'], '2026-08-02 10:00:00', '2026-08-02 11:00:00');
        $this->insertInterval($connection, $first, $seed, $seed['specialityA'], $seed['departmentA'], '2026-08-01 10:00:00', '2026-08-01 11:00:00', 'unit-a');
        $this->insertInterval($connection, $second, $seed, $seed['specialityA'], $seed['departmentA'], '2026-08-02 10:00:00', '2026-08-02 11:00:00', 'unit-b');
        $hidden = $this->insertEvent($connection, $seed['hospitalId'], '2026-08-03 10:00:00', '2026-08-03 11:00:00');
        $this->insertInterval($connection, $hidden, $seed, $seed['specialityA'], $seed['departmentA'], '2026-08-03 10:00:00', '2026-08-03 11:00:00', 'unit-only');
        $this->insertInterval($connection, $hidden, $seed, $seed['specialityB'], $seed['departmentB'], '2026-08-03 10:00:00', '2026-08-03 10:30:00', 'unit-only');

        $profiles = self::getContainer()->get(ClosureProfileQuery::class);
        $criteria = $this->criteria($seed['hospitalId'], '2026-01-01', '2027-01-01');
        $page = $profiles->recurringGroupPage($criteria, new ClosureRecurringProfileTableQuery(1, 25, 'eventCount', 'desc', ''));
        self::assertGreaterThanOrEqual(1, $page->total);
        self::assertNotEmpty($page->rows);
        self::assertSame('Profile Speciality A', $page->rows[0]->specialities[0]);

        $filtered = $profiles->recurringGroupPage($criteria, new ClosureRecurringProfileTableQuery(1, 25, 'eventCount', 'desc', 'Closed Profile Department'));
        self::assertGreaterThanOrEqual(1, $filtered->total);
        self::assertNotEmpty($filtered->rows);

        $missing = $profiles->recurringGroupPage($criteria, new ClosureRecurringProfileTableQuery(1, 25, 'eventCount', 'desc', 'Does Not Exist'));
        self::assertSame(0, $missing->total);
    }

    public function testCourseWeightsEventsExcludesMissingValuesAndDedupesOverlap(): void
    {
        self::bootKernel();
        $seed = $this->seedHospital();
        $connection = self::getContainer()->get(Connection::class);
        $rates = [];
        foreach ([1, 2, 3, 4] as $index => $rate) {
            $day = sprintf('2026-06-%02d', $index + 1);
            $eventId = $this->insertEvent($connection, $seed['hospitalId'], $day.' 10:00:00', $day.' 11:00:00');
            $this->insertInterval($connection, $eventId, $seed, $seed['specialityA'], $seed['departmentA'], $day.' 10:00:00', $day.' 11:00:00', 'unit-rate');
            $this->insertVolume($connection, $eventId, $seed, $seed['specialityA'], $day.' 10:00:00', $day.' 11:00:00', $rate, 1, 'reliable', $day.' 08:00:00', true);
            $rates[] = $eventId;
        }
        $ignored = $this->insertEvent($connection, $seed['hospitalId'], '2026-06-05 10:00:00', '2026-06-05 11:00:00');
        $this->insertInterval($connection, $ignored, $seed, $seed['specialityA'], $seed['departmentA'], '2026-06-05 10:00:00', '2026-06-05 11:00:00', 'unit-rate');
        $this->insertVolume($connection, $ignored, $seed, $seed['specialityA'], '2026-06-05 10:00:00', '2026-06-05 11:00:00', 0, 1, 'insufficient', '2026-06-05 08:00:00', true);

        $partial = $this->insertEvent($connection, $seed['hospitalId'], '2026-06-10 10:30:00', '2026-06-10 16:30:00');
        $this->insertInterval($connection, $partial, $seed, $seed['specialityA'], $seed['departmentB'], '2026-06-10 10:30:00', '2026-06-10 16:30:00', 'unit-partial');
        $this->insertVolume($connection, $partial, $seed, $seed['specialityA'], '2026-06-10 10:00:00', '2026-06-10 11:00:00', 2, 1, 'reliable', '2026-06-10 08:00:00', true);
        $this->insertVolume($connection, $partial, $seed, $seed['specialityA'], '2026-06-10 11:00:00', '2026-06-10 12:00:00', 4, 2, 'reliable', '2026-06-10 08:00:00', true);
        $this->insertVolume($connection, $partial, $seed, $seed['specialityA'], '2026-06-10 10:30:00', '2026-06-10 11:30:00', 0, 1, 'insufficient', '2026-06-10 08:00:00', true);

        $overlapA = $this->insertEvent($connection, $seed['hospitalId'], '2026-06-20 10:00:00', '2026-06-20 11:00:00');
        $overlapB = $this->insertEvent($connection, $seed['hospitalId'], '2026-06-20 10:00:00', '2026-06-20 11:00:00');
        $this->insertInterval($connection, $overlapA, $seed, $seed['specialityB'], $seed['departmentB'], '2026-06-20 10:00:00', '2026-06-20 11:00:00', 'unit-overlap');
        $this->insertInterval($connection, $overlapB, $seed, $seed['specialityB'], $seed['departmentB'], '2026-06-20 10:00:00', '2026-06-20 11:00:00', 'unit-overlap');
        $this->insertVolume($connection, $overlapA, $seed, $seed['specialityB'], '2026-06-20 10:00:00', '2026-06-20 11:00:00', 2, 1, 'reliable', '2026-06-20 08:00:00', true);
        $this->insertVolume($connection, $overlapB, $seed, $seed['specialityB'], '2026-06-20 10:00:00', '2026-06-20 11:00:00', 8, 1, 'reliable', '2026-06-20 09:00:00', true);

        $profiles = self::getContainer()->get(ClosureProfileQuery::class);
        $criteria = $this->criteria($seed['hospitalId'], '2026-01-01', '2027-01-01');
        $rateKey = (string) $profiles->linksForEvent($rates[0], $seed['hospitalId'])->groupKey;
        $partialKey = (string) $profiles->linksForEvent($partial, $seed['hospitalId'])->groupKey;
        $overlapKey = (string) $profiles->linksForEvent($overlapA, $seed['hospitalId'])->groupKey;

        $rated = $profiles->view($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], $rateKey)), ClosureVolumeStratum::Sk1);
        $partialView = $profiles->view($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], $partialKey)), ClosureVolumeStratum::Sk1);
        $overlap = $profiles->view($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], $overlapKey)), ClosureVolumeStratum::Sk1);
        self::assertInstanceOf(ClosureProfileView::class, $rated);
        self::assertInstanceOf(ClosureProfileView::class, $partialView);
        self::assertInstanceOf(ClosureProfileView::class, $overlap);

        $typical = $this->point($rated, 0);
        self::assertSame(4, $typical->eventCount);
        self::assertFalse($typical->lineSuppressed);
        self::assertEqualsWithDelta(2.5, (float) $typical->observedMedian, 0.01);
        self::assertEqualsWithDelta(2.5, (float) $typical->observedMean, 0.01);
        self::assertSame(4, $rated->usableEventCount);

        $slice = $this->point($partialView, 0);
        self::assertSame(1, $slice->eventCount);
        self::assertTrue($slice->lineSuppressed);
        self::assertEqualsWithDelta(3.0, (float) $slice->observedMedian, 0.01);
        self::assertTrue($slice->memberBoundary);

        self::assertTrue($overlap->phase->hoursReused);
        self::assertEqualsWithDelta(2.0, (float) $overlap->phase->dedupedObserved, 0.01);
        self::assertSame(2, $overlap->phase->contributingEvents);
        self::assertTrue($overlap->phase->lineSuppressed);
        self::assertSame(
            $overlap->eventCount,
            self::getContainer()->get(ClosureEventQuery::class)->countEvents($criteria->withProfile(ClosureProfileRef::group($seed['hospitalId'], $overlapKey))),
        );
    }

    public function testCombinedSeriesCountsAssignmentsOncePerEventSlice(): void
    {
        self::bootKernel();
        $seed = $this->seedHospital();
        $connection = self::getContainer()->get(Connection::class);
        $eventId = $this->insertEvent($connection, $seed['hospitalId'], '2026-07-01 10:00:00', '2026-07-01 11:00:00');
        $this->insertInterval($connection, $eventId, $seed, $seed['specialityA'], $seed['departmentA'], '2026-07-01 10:00:00', '2026-07-01 11:00:00', 'unit-combined');
        $this->insertInterval($connection, $eventId, $seed, $seed['specialityB'], $seed['departmentB'], '2026-07-01 10:00:00', '2026-07-01 11:00:00', 'unit-combined');
        $this->insertVolume($connection, $eventId, $seed, $seed['specialityA'], '2026-07-01 10:00:00', '2026-07-01 11:00:00', 2, 1, 'reliable', '2026-07-01 08:00:00', true);
        $this->insertVolume($connection, $eventId, $seed, $seed['specialityB'], '2026-07-01 10:00:00', '2026-07-01 11:00:00', 2, 1, 'reliable', '2026-07-01 08:00:00', true);

        $profiles = self::getContainer()->get(ClosureProfileQuery::class);
        $key = (string) $profiles->linksForEvent($eventId, $seed['hospitalId'])->groupKey;
        $view = $profiles->view(
            $this->criteria($seed['hospitalId'], '2026-01-01', '2027-01-01')->withProfile(ClosureProfileRef::group($seed['hospitalId'], $key)),
            ClosureVolumeStratum::Sk1,
        );
        self::assertInstanceOf(ClosureProfileView::class, $view);
        $combined = null;
        foreach ($view->startCourse as $series) {
            if ($series->combined) {
                $combined = $series;
            }
        }
        self::assertNotNull($combined);
        self::assertEqualsWithDelta(4.0, (float) $this->pointFrom($combined->points, 0)->observedMedian, 0.01);
        self::assertSame(1, $view->eventCount);
    }

    /**
     * @return array{hospitalId: int, importId: int, specialityA: int, specialityB: int, departmentA: int, departmentB: int}
     */
    private function seedHospital(): array
    {
        $user = UserFactory::createOne(['username' => 'closure-profile-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Profile Hospital', 'state' => $state, 'dispatchArea' => $dispatch, 'owner' => $user]);
        $specialityA = SpecialityFactory::createOne(['name' => 'Profile Speciality A']);
        $specialityB = SpecialityFactory::createOne(['name' => 'Profile Speciality B']);
        $departmentA = DepartmentFactory::createOne(['name' => 'Closed Profile Department']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Other Profile Department']);
        $open = DepartmentFactory::createOne(['name' => 'Open Profile Department']);
        AssignmentFactory::createOne(['name' => 'Profile Assignment']);
        IndicationRawFactory::createOne(['name' => 'Profile Raw', 'code' => random_int(1000, 9999)]);
        IndicationNormalizedFactory::createOne(['name' => 'Profile Indication '.bin2hex(random_bytes(3))]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'urgency' => AllocationUrgency::EMERGENCY,
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:30:00'),
            'departmentWasClosed' => true,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $specialityA,
            'department' => $departmentA,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:30:00'),
            'indicationNormalized' => IndicationNormalizedFactory::random(),
        ]);
        AllocationFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
            'urgency' => AllocationUrgency::EMERGENCY,
            'arrivalAt' => new \DateTimeImmutable('2026-05-01 10:45:00'),
            'departmentWasClosed' => false,
            'import' => $import,
            'hospital' => $hospital,
            'speciality' => $specialityA,
            'department' => $open,
            'createdAt' => new \DateTimeImmutable('2026-05-01 10:45:00'),
            'indicationNormalized' => IndicationNormalizedFactory::random(),
        ]);

        return [
            'hospitalId' => (int) $hospital->getId(),
            'importId' => (int) $import->getId(),
            'specialityA' => (int) $specialityA->getId(),
            'specialityB' => (int) $specialityB->getId(),
            'departmentA' => (int) $departmentA->getId(),
            'departmentB' => (int) $departmentB->getId(),
        ];
    }

    /**
     * @param array{hospitalId: int, specialityA: int, specialityB: int, departmentA: int, departmentB: int} $seed
     */
    private function insertInterval(
        Connection $connection,
        int $eventId,
        array $seed,
        int $specialityId,
        int $departmentId,
        string $start,
        string $end,
        string $unit,
    ): void {
        $intervalId = (int) $connection->fetchOne(<<<'SQL'
INSERT INTO closure_analysis_interval (
    event_id, hospital_id, speciality_id, department_id, starts_at, ends_at, reason, facility_kind, closure_unit, fingerprint
) VALUES (
    :event, :hospital, :speciality, :department, :start, :end, 'no_bed_capacity', 'clinic', :unit, :fingerprint
) RETURNING id
SQL, [
            'event' => $eventId,
            'hospital' => $seed['hospitalId'],
            'speciality' => $specialityId,
            'department' => $departmentId,
            'start' => $start,
            'end' => $end,
            'unit' => $unit,
            'fingerprint' => 'profile-'.$eventId.'-'.$specialityId.'-'.$departmentId.'-'.$start,
        ]);
        $connection->insert('closure_analysis_care_level', [
            'analysis_interval_id' => $intervalId,
            'care_level' => 'emergency',
        ]);
    }

    private function insertEvent(Connection $connection, int $hospitalId, string $start, string $end): int
    {
        return (int) $connection->fetchOne(<<<'SQL'
INSERT INTO closure_event (hospital_id, event_type, grouping_key, grouping_rule, starts_at, ends_at)
VALUES (:hospital, 'single', :grouping_key, 'test', :start, :end)
RETURNING id
SQL, [
            'hospital' => $hospitalId,
            'grouping_key' => 'profile-event-'.$hospitalId.'-'.$start.'-'.bin2hex(random_bytes(3)),
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * @param array{hospitalId: int} $seed
     */
    private function insertVolume(
        Connection $connection,
        int $eventId,
        array $seed,
        int $specialityId,
        string $bucketStart,
        string $bucketEnd,
        float $observed,
        float $expected,
        string $quality,
        string $cutoff,
        bool $inClosure,
        int $urgency = 1,
    ): void {
        $connection->insert('closure_volume_hour', [
            'event_id' => $eventId,
            'scope' => 'speciality',
            'hospital_id' => $seed['hospitalId'],
            'speciality_id' => $specialityId,
            'department_id' => 0,
            'urgency_code' => $urgency,
            'stratum' => 'base',
            'bucket_start' => $bucketStart,
            'bucket_end' => $bucketEnd,
            'in_closure' => $inClosure ? 1 : 0,
            'observed_area' => $observed,
            'expected_area' => $expected,
            'evaluable_seconds' => 3600,
            'bucket_seconds' => 3600,
            'reference_slot_count' => 8,
            'reference_assignment_count' => 8,
            'reference_mode' => 'weekday_hour',
            'influenced' => 0,
            'quality' => $quality,
            'reference_cutoff' => $cutoff,
        ]);
    }

    private function criteria(int $hospitalId, string $from, string $to): ClosureAnalyticsCriteria
    {
        return new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospitalId]),
            new StatisticsPeriodBounds(new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
            TimeSeriesGrain::Month,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospitalId, null, StatisticsFilterPeriod::AllTime, null),
        );
    }

    private function department(ClosureProfileView $view, string $name): ClosureProfileDepartment
    {
        foreach ($view->departments as $department) {
            if ($department->name === $name) {
                return $department;
            }
        }
        self::fail('Missing department '.$name);
    }

    private function yearCount(ClosureProfileView $view, int $year): int
    {
        foreach ($view->coverage->yearHeatmap as $row) {
            foreach ($row as $cell) {
                if ($cell['year'] === $year) {
                    return $cell['count'];
                }
            }
        }
        self::fail('Missing coverage year '.$year);
    }

    private function point(ClosureProfileView $view, int $offset): ClosureProfileCoursePoint
    {
        self::assertNotEmpty($view->startCourse);

        return $this->pointFrom($view->startCourse[0]->points, $offset);
    }

    /**
     * @param list<ClosureProfileCoursePoint> $points
     */
    private function pointFrom(array $points, int $offset): ClosureProfileCoursePoint
    {
        foreach ($points as $point) {
            if ($point->offset === $offset) {
                return $point;
            }
        }
        self::fail('Missing course offset '.$offset);
    }
}
