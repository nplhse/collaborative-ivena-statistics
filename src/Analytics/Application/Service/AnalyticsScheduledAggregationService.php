<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\Contract\AnalyticsScheduledAggregationRunnerInterface;
use App\Analytics\Application\DTO\AnalyticsScheduledAggregationResult;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Infrastructure\Query\AnalyticsDayAggregationQuery;

/** @psalm-suppress UnusedClass Wired via AnalyticsScheduledAggregationRunnerInterface autowiring. */
final readonly class AnalyticsScheduledAggregationService implements AnalyticsScheduledAggregationRunnerInterface
{
    private const int CATCH_UP_LIMIT = 30;

    public function __construct(
        private AnalyticsDailyAggregationService $aggregationService,
        private AnalyticsRawRetentionService $retentionService,
        private AnalyticsDayAggregationQuery $aggregationQuery,
    ) {
    }

    #[\Override]
    public function run(): AnalyticsScheduledAggregationResult
    {
        $yesterday = AnalyticsCalendar::yesterday();
        $datesByKey = [$yesterday->format('Y-m-d') => $yesterday];

        foreach ($this->aggregationQuery->findUnaggregatedCompletedDates(self::CATCH_UP_LIMIT, $yesterday) as $date) {
            $datesByKey[$date->format('Y-m-d')] = $date;
        }

        ksort($datesByKey);
        $dates = array_values($datesByKey);

        $totalRawRequests = 0;
        $totalRawEvents = 0;
        $totalAggregateRows = 0;

        foreach ($dates as $date) {
            $result = $this->aggregationService->aggregateForDate($date);
            $totalRawRequests += $result->rawRequestCount;
            $totalRawEvents += $result->rawEventCount;
            $totalAggregateRows += $result->aggregateRowsWritten;
        }

        $retention = $this->retentionService->purgeExpiredRaw();

        return new AnalyticsScheduledAggregationResult(
            dates: array_map(
                static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'),
                $dates,
            ),
            daysProcessed: \count($dates),
            totalRawRequests: $totalRawRequests,
            totalRawEvents: $totalRawEvents,
            totalAggregateRows: $totalAggregateRows,
            rawRowsDeleted: $retention->rawRowsDeleted(),
        );
    }
}
