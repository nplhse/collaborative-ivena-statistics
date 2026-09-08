<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AnalyticsAggregationResult;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsAggregationRun;
use App\Analytics\Domain\Entity\AnalyticsEventDaily;
use App\Analytics\Domain\Entity\AnalyticsFilterAreaDaily;
use App\Analytics\Domain\Entity\AnalyticsFilterParamDaily;
use App\Analytics\Domain\Entity\AnalyticsRequestDaily;
use App\Analytics\Domain\Entity\AnalyticsSessionBoundaryDaily;
use App\Analytics\Domain\Entity\AnalyticsTransitionDaily;
use App\Analytics\Domain\Entity\AnalyticsUniquesDaily;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\Enum\SessionBoundaryKind;
use App\Analytics\Infrastructure\Persistence\AnalyticsDailyAggregateStore;
use App\Analytics\Infrastructure\Query\AnalyticsDayAggregationQuery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AnalyticsDailyAggregationService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsDayAggregationQuery $aggregationQuery,
        private AnalyticsDailyAggregateStore $aggregateStore,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function aggregateForDate(\DateTimeImmutable $date): AnalyticsAggregationResult
    {
        $calendarDate = AnalyticsCalendar::startOfDay($date);

        return $this->entityManager->wrapInTransaction(function () use ($calendarDate): AnalyticsAggregationResult {
            $this->aggregateStore->deleteByDate($calendarDate);
            $this->entityManager->clear();

            $rawRequestCount = $this->aggregationQuery->countRequests($calendarDate);
            $rawEventCount = $this->aggregationQuery->countEvents($calendarDate);
            $rowsWritten = 0;

            $rowsWritten += $this->persistRequestDaily($calendarDate);
            $rowsWritten += $this->persistEventDaily($calendarDate);
            $rowsWritten += $this->persistFilterParams($calendarDate);
            $rowsWritten += $this->persistFilterAreas($calendarDate);
            $rowsWritten += $this->persistTransitions($calendarDate);
            $rowsWritten += $this->persistSessionBoundaries($calendarDate);
            $rowsWritten += $this->persistUniques($calendarDate);

            $this->entityManager->persist(new AnalyticsAggregationRun(
                date: $calendarDate,
                rawRequestCount: $rawRequestCount,
                rawEventCount: $rawEventCount,
                aggregateRowsWritten: $rowsWritten,
            ));
            ++$rowsWritten;

            $this->entityManager->flush();

            return new AnalyticsAggregationResult(
                date: $calendarDate->format('Y-m-d'),
                rawRequestCount: $rawRequestCount,
                rawEventCount: $rawEventCount,
                aggregateRowsWritten: $rowsWritten,
            );
        });
    }

    private function persistRequestDaily(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchRequestDaily($date) as $row) {
            $this->entityManager->persist(new AnalyticsRequestDaily(
                date: $date,
                featureArea: FeatureArea::from($row['featureArea']),
                routeName: $row['routeName'],
                isAuthenticated: $row['isAuthenticated'],
                userRole: $row['userRole'],
                requestCount: $row['requestCount'],
                errorCount: $row['errorCount'],
                sumDurationMs: $row['sumDurationMs'],
                sumDbQueryCount: $row['sumDbQueryCount'],
                sumDbTimeMs: $row['sumDbTimeMs'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistEventDaily(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchEventDaily($date) as $row) {
            $featureArea = null;
            if (null !== $row['featureArea'] && '' !== $row['featureArea']) {
                $featureArea = FeatureArea::tryFrom($row['featureArea']);
            }
            $this->entityManager->persist(new AnalyticsEventDaily(
                date: $date,
                eventName: $row['eventName'],
                featureArea: $featureArea,
                userRole: $row['userRole'],
                eventCount: $row['eventCount'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistFilterParams(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchFilterParams($date) as $row) {
            $this->entityManager->persist(new AnalyticsFilterParamDaily(
                date: $date,
                paramName: $row['paramName'],
                usageCount: $row['usageCount'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistFilterAreas(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchFilterAreas($date) as $row) {
            $this->entityManager->persist(new AnalyticsFilterAreaDaily(
                date: $date,
                featureArea: FeatureArea::from($row['featureArea']),
                withFilters: $row['withFilters'],
                withoutFilters: $row['withoutFilters'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistTransitions(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchTransitions($date) as $row) {
            $this->entityManager->persist(new AnalyticsTransitionDaily(
                date: $date,
                fromRoute: $row['fromRoute'],
                toRoute: $row['toRoute'],
                transitionCount: $row['transitionCount'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistSessionBoundaries(\DateTimeImmutable $date): int
    {
        $count = 0;
        foreach ($this->aggregationQuery->fetchSessionBoundaries($date) as $row) {
            $this->entityManager->persist(new AnalyticsSessionBoundaryDaily(
                date: $date,
                routeName: $row['routeName'],
                kind: SessionBoundaryKind::from($row['kind']),
                sessionCount: $row['sessionCount'],
            ));
            ++$count;
        }

        return $count;
    }

    private function persistUniques(\DateTimeImmutable $date): int
    {
        $uniques = $this->aggregationQuery->fetchUniques($date);
        if (null === $uniques) {
            return 0;
        }

        $this->entityManager->persist(new AnalyticsUniquesDaily(
            date: $date,
            dau: $uniques['dau'],
            distinctVisitors: $uniques['distinctVisitors'],
            distinctSessions: $uniques['distinctSessions'],
        ));

        return 1;
    }
}
