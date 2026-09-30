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
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureEventQuery;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureTemporalQuery;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ClosureAnalyticsQueryTest extends KernelTestCase
{
    use Factories;

    public function testClipsDurationAndKeepsHospitalLocalGroupsSeparate(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-analytics-test']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Closure Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $otherHospital = HospitalFactory::createOne(['name' => 'Other Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Closure Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Closure Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $otherImport = ImportFactory::createOne(['hospital' => $otherHospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2025-12-31 23:00:00', '2026-01-01 02:00:00', 'group-1', 'Local A');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-01-31 23:00:00', '2026-02-01 01:00:00', null, 'Local A');
        $this->insertInterval($connection, $otherHospital->getId(), $otherImport->getId(), $speciality->getId(), $department->getId(), '2026-01-10 10:00:00', '2026-01-10 12:00:00', 'other', 'Local A');

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Year,
            2026,
        );
        $criteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-01')),
            TimeSeriesGrain::Month,
            $filter,
        );
        $temporal = self::getContainer()->get(ClosureTemporalQuery::class);
        $metrics = $temporal->fetchMetrics($criteria);
        self::assertSame(2, $metrics->closureCount);
        self::assertSame(240, $metrics->summedMinutes);
        self::assertSame(240, $metrics->closedMinutes);
        self::assertSame(240, $metrics->singleMinutes);
        self::assertSame(0, $metrics->multipleMinutes);
        self::assertSame(44_700, $metrics->observedMinutes);

        $temporalSeries = $temporal->fetchTimeSeries($criteria);
        self::assertSame(['2026-01', '2026-02'], array_map(static fn ($row): string => $row->key, $temporalSeries));
        self::assertSame([180, 60], array_map(static fn ($row): int => $row->closedMinutes, $temporalSeries));
        $timeline = $temporal->fetchTimelineGrid($criteria, 'month');
        self::assertNotEmpty($timeline);
        self::assertNotEmpty($timeline[0]->cells);
        self::assertSame(240, array_sum(array_map(
            static fn ($row): int => array_sum(array_map(static fn ($cell): int => $cell->closedMinutes, $row->cells)),
            $timeline,
        )));
        $segments = $temporal->fetchTimelineSegments($criteria);
        self::assertCount(3, $segments, 'The interval crossing midnight is split into two exact day segments.');
        self::assertSame('Closure Hospital', $segments[0]->hospitalName);
        $units = $temporal->fetchBreakdown($criteria, 'closure_unit');
        self::assertCount(1, $units);
        self::assertSame('Closure Hospital · Local A', $units[0]->name);

        $eventQuery = self::getContainer()->get(ClosureEventQuery::class);
        $events = $eventQuery->fetchEvents($criteria, 0, 25, 'startsAt', 'desc');
        self::assertSame(2, $eventQuery->countEvents($criteria));
        self::assertCount(2, $events);
        $iterated = iterator_to_array($eventQuery->iterateEvents($criteria, 'startsAt', 'desc'), false);
        self::assertCount(2, $iterated);
        self::assertSame($events[0]->key, $iterated[0]->key);
        self::assertNotEmpty($events[0]->children);
        $ascending = $eventQuery->fetchEvents($criteria, 0, 25, 'startsAt', 'asc');
        self::assertTrue($ascending[0]->startsAt < $ascending[1]->startsAt);
        self::assertSame(
            $ascending[1]->key,
            $eventQuery->fetchEvents($criteria, 0, 1, 'startsAt', 'desc')[0]->key,
        );
        self::assertNotNull($eventQuery->fetchEvent($criteria, $events[0]->key));
        $children = $eventQuery->fetchChildren($criteria, $events[0]->key);
        self::assertNotEmpty($children);
    }

    public function testParallelChildrenOfOneGroupCountAsOneActiveEvent(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-overlap-test']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Overlap Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Overlap Speciality']);
        $importA = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);
        $departmentIds = [];

        for ($index = 1; $index <= 5; ++$index) {
            $department = DepartmentFactory::createOne(['name' => 'Overlap Department '.$index]);
            $departmentIds[] = $department->getId();
            foreach ([$importA, $importB] as $import) {
                $this->insertInterval(
                    $connection,
                    $hospital->getId(),
                    $import->getId(),
                    $speciality->getId(),
                    $department->getId(),
                    '2026-03-01 10:00:00',
                    '2026-03-01 12:00:00',
                    'parallel-group',
                    'Parallel unit',
                );
            }
        }

        $filter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospital->getId(),
            null,
            StatisticsFilterPeriod::Month,
            2026,
            3,
        );
        $criteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')),
            TimeSeriesGrain::Day,
            $filter,
        );

        $metrics = self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($criteria);
        self::assertSame(5, $metrics->closureCount);
        self::assertSame(1, $metrics->eventCount);
        self::assertSame(600, $metrics->summedMinutes);
        self::assertSame(120, $metrics->closedMinutes);
        self::assertSame(120, $metrics->singleMinutes);
        self::assertSame(0, $metrics->multipleMinutes);
        self::assertSame(120, $metrics->observedMinutes);

        $eventTypes = self::getContainer()->get(ClosureTemporalQuery::class)->fetchBreakdown($criteria, 'event_type');
        self::assertSame(['group', 'cluster', 'single'], array_map(static fn ($row): string => $row->key, $eventTypes));
        self::assertSame([1, 0, 0], array_map(static fn ($row): int => $row->closureCount, $eventTypes));

        $eventQuery = self::getContainer()->get(ClosureEventQuery::class);
        $events = $eventQuery->fetchEvents($criteria, 0, 25, 'startsAt', 'desc');
        self::assertSame(1, $eventQuery->countEvents($criteria));
        self::assertSame(5, $events[0]->closureCount);
        self::assertSame(600, $events[0]->summedMinutes);
        self::assertSame(120, $events[0]->actualMinutes);
        $segments = self::getContainer()->get(ClosureTemporalQuery::class)->fetchTimelineSegments($criteria);
        self::assertCount(5, $segments);
        self::assertCount(1, array_unique(array_map(static fn ($segment): string => $segment->eventKey, $segments)));

        $filteredCriteria = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            array_slice($departmentIds, 0, 2),
        );
        $filteredMetrics = self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($filteredCriteria);
        self::assertSame(2, $filteredMetrics->closureCount);
        self::assertSame(1, $filteredMetrics->eventCount);
        self::assertSame(240, $filteredMetrics->summedMinutes);
        self::assertSame(120, $filteredMetrics->closedMinutes);
        self::assertSame(0, $filteredMetrics->multipleMinutes);

        $groupOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            eventTypes: [ClosureEventType::Group->value],
        );
        self::assertSame(5, $eventQuery->countIntervals($groupOnly));
        self::assertSame(1, $eventQuery->countEvents($groupOnly));
        self::assertSame(1, self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($groupOnly)->eventCount);

        $singleOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            eventTypes: [ClosureEventType::Single->value],
        );
        self::assertSame(0, $eventQuery->countEvents($singleOnly));
    }

    public function testCoincidentUngroupedIntervalsFormAStableAnalyticalCluster(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-cluster-test']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Cluster Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Cluster Speciality']);
        $departmentA = DepartmentFactory::createOne(['name' => 'Cluster Department A']);
        $departmentB = DepartmentFactory::createOne(['name' => 'Cluster Department B']);
        $departmentC = DepartmentFactory::createOne(['name' => 'Cluster Department C']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $departmentA->getId(), '2026-04-10 10:00:00', '2026-04-10 12:00:00', null, 'Cluster A');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $departmentB->getId(), '2026-04-10 10:00:00', '2026-04-10 12:00:00', null, 'Cluster B');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $departmentC->getId(), '2026-04-10 10:00:00', '2026-04-10 11:00:00', null, 'Different end');

        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::Month, 2026, 4);
        $criteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-04-10'), new \DateTimeImmutable('2026-04-11')),
            TimeSeriesGrain::Day,
            $filter,
        );
        $temporal = self::getContainer()->get(ClosureTemporalQuery::class);
        $metrics = $temporal->fetchMetrics($criteria);
        self::assertSame(3, $metrics->closureCount);
        self::assertSame(2, $metrics->eventCount);
        self::assertSame(300, $metrics->summedMinutes);
        self::assertSame(120, $metrics->closedMinutes);
        self::assertSame(60, $metrics->singleMinutes);
        self::assertSame(60, $metrics->multipleMinutes);

        $eventTypes = $temporal->fetchBreakdown($criteria, 'event_type');
        self::assertSame(['group', 'cluster', 'single'], array_map(static fn ($row): string => $row->key, $eventTypes));
        self::assertSame([0, 1, 1], array_map(static fn ($row): int => $row->closureCount, $eventTypes));
        self::assertSame(120, $eventTypes[1]->actualMinutes);
        self::assertSame(60, $eventTypes[2]->actualMinutes);

        $eventQuery = self::getContainer()->get(ClosureEventQuery::class);
        $events = $eventQuery->fetchEvents($criteria, 0, 25, 'closureCount', 'desc');
        $cluster = $events[0];
        self::assertSame(ClosureEventType::Cluster, $cluster->type);
        self::assertStringStartsWith('cluster:'.$hospital->getId().':', $cluster->key);
        self::assertSame(2, $cluster->closureCount);
        self::assertCount(2, $eventQuery->fetchChildren($criteria, $cluster->key));
        self::assertSame($cluster->key, $eventQuery->fetchEvent($criteria, $cluster->key)?->key);
        $intervals = $eventQuery->fetchIntervals($criteria, 0, 25, 'department', 'asc');
        self::assertSame(3, $eventQuery->countIntervals($criteria));
        self::assertCount(3, $intervals);
        $iteratedIntervals = iterator_to_array($eventQuery->iterateIntervals($criteria, 'department', 'asc'), false);
        self::assertCount(3, $iteratedIntervals);
        self::assertSame($intervals[0]->id, $iteratedIntervals[0]->id);
        self::assertSame('Cluster Department A', $intervals[0]->departmentName);
        self::assertSame('Cluster Speciality', $intervals[0]->specialityName);
        self::assertSame('Cluster A', $intervals[0]->closureUnit);
        self::assertSame(ClosureEventType::Cluster, $intervals[0]->eventType);

        $segments = $temporal->fetchTimelineSegments($criteria);
        $clusterSegments = array_values(array_filter(
            $segments,
            static fn ($segment): bool => ClosureEventType::Cluster === $segment->eventType,
        ));
        self::assertCount(2, $clusterSegments);
        self::assertCount(1, array_unique(array_map(static fn ($segment): string => $segment->eventKey, $clusterSegments)));
        self::assertTrue(array_all($clusterSegments, static fn ($segment): bool => $segment->parallel));

        $filteredCriteria = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            [$departmentA->getId()],
        );
        $filteredEvent = $eventQuery->fetchEvents($filteredCriteria, 0, 25, 'startsAt', 'asc')[0];
        self::assertSame(ClosureEventType::Cluster, $filteredEvent->type);
        self::assertSame($cluster->key, $filteredEvent->key);
        self::assertSame(1, $filteredEvent->closureCount);

        $clusterOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            eventTypes: [ClosureEventType::Cluster->value],
        );
        self::assertSame(1, $eventQuery->countEvents($clusterOnly));
        self::assertSame(2, $eventQuery->countIntervals($clusterOnly));
        self::assertSame(2, $temporal->fetchMetrics($clusterOnly)->closureCount);
        self::assertSame(1, $temporal->fetchMetrics($clusterOnly)->eventCount);
        self::assertSame(ClosureEventType::Cluster, $eventQuery->fetchEvents($clusterOnly, 0, 1, 'startsAt', 'asc')[0]->type);

        $singleOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            eventTypes: [ClosureEventType::Single->value],
        );
        self::assertSame(1, $eventQuery->countEvents($singleOnly));
        self::assertSame(1, $eventQuery->countIntervals($singleOnly));
        self::assertSame(ClosureEventType::Single, $eventQuery->fetchEvents($singleOnly, 0, 1, 'startsAt', 'asc')[0]->type);

        $groupOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            eventTypes: [ClosureEventType::Group->value],
        );
        self::assertSame(0, $eventQuery->countEvents($groupOnly));
        self::assertSame(0, $temporal->fetchMetrics($groupOnly)->eventCount);
    }

    public function testEstimatedCoverageKeepsGapsAndDstElapsedTimeHonest(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-coverage-test']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Coverage Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Coverage Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Coverage Department']);
        $importA = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $importA->getId(), $speciality->getId(), $department->getId(), '2026-01-01 00:00:00', '2026-01-01 01:00:00', 'gap-a', 'Coverage');
        $this->insertInterval($connection, $hospital->getId(), $importA->getId(), $speciality->getId(), $department->getId(), '2026-01-01 03:00:00', '2026-01-01 04:00:00', 'gap-b', 'Coverage');
        $this->insertInterval($connection, $hospital->getId(), $importB->getId(), $speciality->getId(), $department->getId(), '2026-01-02 00:00:00', '2026-01-02 01:00:00', 'gap-c', 'Coverage');

        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::Month, 2026, 1);
        $criteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-03')),
            TimeSeriesGrain::Day,
            $filter,
        );
        $metrics = self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($criteria);
        self::assertSame(300, $metrics->observedMinutes);
        self::assertSame(180, $metrics->closedMinutes);
        self::assertSame(2_880, $metrics->calendarMinutes);

        $dstImport = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $this->insertInterval($connection, $hospital->getId(), $dstImport->getId(), $speciality->getId(), $department->getId(), '2026-03-29 00:00:00', '2026-03-30 00:00:00', 'dst', 'Coverage');
        $dstCriteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-03-29'), new \DateTimeImmutable('2026-03-30')),
            TimeSeriesGrain::Day,
            $filter,
        );
        $dstMetrics = self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($dstCriteria);
        self::assertSame(1_380, $dstMetrics->summedMinutes);
        self::assertSame(1_380, $dstMetrics->observedMinutes);
        self::assertSame(1_380, $dstMetrics->calendarMinutes);
        $springGrid = self::getContainer()->get(ClosureTemporalQuery::class)->fetchTimelineGrid($dstCriteria, 'day');
        self::assertCount(23, $springGrid[0]->cells);

        $fallImport = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $this->insertInterval($connection, $hospital->getId(), $fallImport->getId(), $speciality->getId(), $department->getId(), '2026-10-25 00:00:00', '2026-10-26 00:00:00', 'dst-fall', 'Coverage');
        $fallCriteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-10-26')),
            TimeSeriesGrain::Day,
            $filter,
        );
        $fallMetrics = self::getContainer()->get(ClosureTemporalQuery::class)->fetchMetrics($fallCriteria);
        self::assertSame(1_500, $fallMetrics->summedMinutes);
        self::assertSame(1_500, $fallMetrics->observedMinutes);
        self::assertSame(1_500, $fallMetrics->calendarMinutes);
        $fallGrid = self::getContainer()->get(ClosureTemporalQuery::class)->fetchTimelineGrid($fallCriteria, 'day');
        self::assertCount(25, $fallGrid[0]->cells);

        $heatmap = self::getContainer()->get(ClosureTemporalQuery::class)->fetchHeatmap($criteria);
        self::assertNotEmpty($heatmap);
        self::assertContains(60, array_map(static fn ($cell): int => $cell->closedMinutes, $heatmap));
    }

    public function testMultiHospitalScopeUsesHospitalTimeAndOverlappingGroupsAreNotAdditive(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-multi-hospital']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospitalA = HospitalFactory::createOne(['name' => 'Multi A', 'state' => $state, 'dispatchArea' => $dispatch]);
        $hospitalB = HospitalFactory::createOne(['name' => 'Multi B', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Multi Speciality']);
        $sharedDepartment = DepartmentFactory::createOne(['name' => 'Shared Department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => 'Other Department']);
        $importA = ImportFactory::createOne(['hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['hospital' => $hospitalB, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospitalA->getId(), $importA->getId(), $speciality->getId(), $sharedDepartment->getId(), '2026-06-01 00:00:00', '2026-06-01 03:00:00', 'overlap-a', 'Multi');
        $this->insertInterval($connection, $hospitalA->getId(), $importA->getId(), $speciality->getId(), $sharedDepartment->getId(), '2026-06-01 01:00:00', '2026-06-01 04:00:00', 'overlap-b', 'Multi');
        $this->insertInterval($connection, $hospitalB->getId(), $importB->getId(), $speciality->getId(), $sharedDepartment->getId(), '2026-06-01 00:00:00', '2026-06-01 01:00:00', 'overlap-c', 'Multi');
        $this->insertInterval($connection, $hospitalB->getId(), $importB->getId(), $speciality->getId(), $otherDepartment->getId(), '2026-06-01 03:00:00', '2026-06-01 04:00:00', 'overlap-d', 'Multi', 'inpatient');

        $filter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::Month, 2026, 6);
        $criteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02')),
            TimeSeriesGrain::Day,
            $filter,
        );
        $query = self::getContainer()->get(ClosureTemporalQuery::class);
        $metrics = $query->fetchMetrics($criteria);

        self::assertSame(480, $metrics->observedMinutes);
        self::assertSame(360, $metrics->closedMinutes);
        self::assertSame(480, $metrics->summedMinutes);
        self::assertSame(240, $metrics->singleMinutes);
        self::assertSame(120, $metrics->multipleMinutes);
        self::assertSame([], $query->fetchClosureUnitChoices($criteria));
        $hospitalChoices = $query->fetchHospitalChoices($criteria);
        self::assertCount(2, $hospitalChoices);
        self::assertSame(['Multi A', 'Multi B'], array_column($hospitalChoices, 'name'));

        $hospitalAOnly = new ClosureAnalyticsCriteria(
            $criteria->scope,
            $criteria->period,
            $criteria->timeSeriesGrain,
            $criteria->filter,
            hospitalIds: [$hospitalA->getId()],
        );
        $hospitalAMetrics = $query->fetchMetrics($hospitalAOnly);
        self::assertSame(2, $hospitalAMetrics->closureCount);
        self::assertSame(240, $hospitalAMetrics->closedMinutes);
        self::assertSame(480, $hospitalAMetrics->observedMinutes, 'Coverage remains hospital-scoped.');

        $hospitalFilter = new StatisticsFilter(
            StatisticsFilterScope::Hospital,
            $hospitalA->getId(),
            null,
            StatisticsFilterPeriod::Month,
            2026,
            6,
        );
        $unitCriteria = new ClosureAnalyticsCriteria(
            scope: new StatisticsScopeCriteria([$hospitalA->getId()]),
            period: $criteria->period,
            timeSeriesGrain: TimeSeriesGrain::Day,
            filter: $hospitalFilter,
            closureUnits: ['Multi'],
        );
        self::assertSame(
            [['value' => 'Multi', 'name' => 'Multi']],
            $query->fetchClosureUnitChoices($unitCriteria),
        );
        self::assertSame(2, $query->fetchMetrics($unitCriteria)->closureCount);
        self::assertSame(240, $query->fetchMetrics($unitCriteria)->closedMinutes);
        self::assertCount(1, $query->fetchHospitalChoices($unitCriteria));
        self::assertSame('Multi', $query->fetchBreakdown($unitCriteria, 'closure_unit')[0]->key);

        $missingUnitCriteria = new ClosureAnalyticsCriteria(
            scope: $unitCriteria->scope,
            period: $unitCriteria->period,
            timeSeriesGrain: TimeSeriesGrain::Day,
            filter: $hospitalFilter,
            closureUnits: ['Missing'],
        );
        self::assertSame(0, $query->fetchMetrics($missingUnitCriteria)->closureCount);

        $myHospitalsFilter = new StatisticsFilter(
            StatisticsFilterScope::MyHospitals,
            null,
            null,
            StatisticsFilterPeriod::Month,
            2026,
            6,
        );
        $myHospitalsUnitCriteria = new ClosureAnalyticsCriteria(
            scope: new StatisticsScopeCriteria([$hospitalA->getId(), $hospitalB->getId()]),
            period: $criteria->period,
            timeSeriesGrain: TimeSeriesGrain::Day,
            filter: $myHospitalsFilter,
            closureUnits: [$hospitalA->getId().':Multi'],
        );
        self::assertSame([
            ['value' => $hospitalA->getId().':Multi', 'name' => 'Multi A · Multi'],
            ['value' => $hospitalB->getId().':Multi', 'name' => 'Multi B · Multi'],
        ], $query->fetchClosureUnitChoices($myHospitalsUnitCriteria));
        self::assertSame(2, $query->fetchMetrics($myHospitalsUnitCriteria)->closureCount);
        self::assertSame(
            $hospitalA->getId().':Multi',
            $query->fetchBreakdown($myHospitalsUnitCriteria, 'closure_unit')[0]->key,
        );

        $departments = $query->fetchBreakdown($criteria, 'department');
        $shared = array_values(array_filter($departments, static fn ($row): bool => 'Shared Department' === $row->name))[0];
        self::assertSame(420, $shared->summedMinutes);
        self::assertSame(300, $shared->actualMinutes);
        self::assertSame(480, $shared->observedMinutes);

        $filteredCriteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02')),
            TimeSeriesGrain::Day,
            $filter,
            [$otherDepartment->getId()],
        );
        $filteredMetrics = $query->fetchMetrics($filteredCriteria);
        self::assertSame(1, $filteredMetrics->closureCount);
        self::assertSame(1, $filteredMetrics->eventCount);
        self::assertSame(60, $filteredMetrics->summedMinutes);
        self::assertSame(60, $filteredMetrics->closedMinutes);
        self::assertSame(480, $filteredMetrics->observedMinutes, 'Coverage remains hospital-scoped.');
        self::assertSame(
            60,
            array_sum(array_map(static fn ($row): int => $row->closedMinutes, $query->fetchTimeSeries($filteredCriteria))),
        );
        self::assertSame(
            60,
            array_sum(array_map(static fn ($cell): int => $cell->closedMinutes, $query->fetchHeatmap($filteredCriteria))),
        );

        $fullyFilteredCriteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02')),
            TimeSeriesGrain::Day,
            $filter,
            [$otherDepartment->getId()],
            [$speciality->getId()],
            ['inpatient'],
        );
        self::assertSame(60, $query->fetchMetrics($fullyFilteredCriteria)->closedMinutes);

        $wrongCareLevelCriteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            $fullyFilteredCriteria->period,
            TimeSeriesGrain::Day,
            $filter,
            [$otherDepartment->getId()],
            [$speciality->getId()],
            ['emergency'],
        );
        self::assertSame(0, $query->fetchMetrics($wrongCareLevelCriteria)->closedMinutes);

        $wrongReasonCriteria = new ClosureAnalyticsCriteria(
            StatisticsScopeCriteria::public(),
            $fullyFilteredCriteria->period,
            TimeSeriesGrain::Day,
            $filter,
            [$otherDepartment->getId()],
            [$speciality->getId()],
            ['inpatient'],
            ['technical_fault'],
        );
        self::assertSame(0, $query->fetchMetrics($wrongReasonCriteria)->closedMinutes);

        $events = self::getContainer()->get(ClosureEventQuery::class)->fetchEvents($criteria, 0, 25, 'startsAt', 'desc');
        self::assertCount(4, $events);
        $segments = $query->fetchTimelineSegments($criteria);
        self::assertSame(['Multi A', 'Multi B'], array_values(array_unique(array_map(
            static fn ($segment): string => $segment->hospitalName,
            $segments,
        ))));
        self::assertTrue(array_any(
            $segments,
            static fn ($segment): bool => 'Multi A' === $segment->hospitalName && $segment->parallel,
        ));
        self::assertFalse(array_any(
            $segments,
            static fn ($segment): bool => 'Multi B' === $segment->hospitalName && $segment->parallel,
        ));
    }

    public function testSameDayDepartmentQueryExcludesTheCurrentEventAndOtherDepartments(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['username' => 'closure-sameday-test']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Same Day Hospital', 'state' => $state, 'dispatchArea' => $dispatch]);
        $speciality = SpecialityFactory::createOne(['name' => 'Same Day Speciality']);
        $department = DepartmentFactory::createOne(['name' => 'Same Day Department']);
        $otherDepartment = DepartmentFactory::createOne(['name' => 'Ignored Department']);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        $connection = self::getContainer()->get(Connection::class);

        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-08-12 10:00:00', '2026-08-12 12:00:00', 'same-day-group', 'Unit');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $department->getId(), '2026-08-12 14:00:00', '2026-08-12 16:00:00', null, 'Unit');
        $this->insertInterval($connection, $hospital->getId(), $import->getId(), $speciality->getId(), $otherDepartment->getId(), '2026-08-12 18:00:00', '2026-08-12 19:00:00', null, 'Unit');

        $criteria = new ClosureAnalyticsCriteria(
            new StatisticsScopeCriteria([$hospital->getId()]),
            new StatisticsPeriodBounds(new \DateTimeImmutable('2026-08-12'), new \DateTimeImmutable('2026-08-13')),
            TimeSeriesGrain::Day,
            new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::Month, 2026, 8),
            [$department->getId()],
            hospitalIds: [$hospital->getId()],
        );
        $related = self::getContainer()->get(ClosureEventQuery::class)->fetchSameDayDepartmentIntervals(
            $criteria,
            'group:'.$hospital->getId().':same-day-group',
        );

        self::assertCount(1, $related);
        self::assertSame(ClosureEventType::Single, $related[0]->eventType);
        self::assertSame('Same Day Department', $related[0]->departmentName);
        self::assertStringStartsWith('interval:', $related[0]->eventKey);
    }

    private function insertInterval(
        Connection $connection,
        int $hospitalId,
        int $importId,
        int $specialityId,
        int $departmentId,
        string $startsAt,
        string $endsAt,
        ?string $groupId,
        string $unit,
        string $careLevel = 'emergency',
    ): void {
        $connection->insert('closure_interval', [
            'hospital_id' => $hospitalId,
            'import_id' => $importId,
            'speciality_id' => $specialityId,
            'department_id' => $departmentId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'care_level' => $careLevel,
            'reason' => 'no_bed_capacity',
            'facility_kind' => 'clinic',
            'closure_unit' => $unit,
            'source_group_id' => $groupId,
            'source_recorded_at' => $startsAt,
            'source_changed_at' => $startsAt,
        ]);
    }
}
