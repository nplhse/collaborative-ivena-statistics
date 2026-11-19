<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\UsageAnalyticsOverviewDto;
use App\Analytics\Application\DTO\UsageTrendDto;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Infrastructure\Query\AnalyticsReportingQuery;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;

final readonly class AnalyticsDashboardService
{
    private const int MAU_DAYS = 30;
    private const int TREND_DAYS = 90;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsRequestRepository $requestRepository,
        private AnalyticsReportingQuery $reportingQuery,
    ) {
    }

    public function getOverview(): UsageAnalyticsOverviewDto
    {
        $from7 = AnalyticsCalendar::daysAgo(6);

        return new UsageAnalyticsOverviewDto(
            requestsToday: $this->reportingQuery->countSince(AnalyticsCalendar::startOfToday()),
            requestsLast7Days: $this->reportingQuery->countSince($from7),
            requestsLast30Days: $this->reportingQuery->countSince(AnalyticsCalendar::daysAgo(29)),
            featureAreas: $this->reportingQuery->featureAreaDistributionSince($from7),
            topRoutes: $this->reportingQuery->topRoutesSince($from7),
            authenticationSplit: $this->reportingQuery->authenticationSplitSince($from7),
            retention: $this->requestRepository->retentionSnapshot(),
        );
    }

    /**
     * Distinct users today (DAU), in the last 7 days (WAU) and in the last 30 days (MAU), Berlin calendar days
     * including today; the same numbers as on the usage analytics overview. `previousMau` is the stored MAU of the
     * 30 days before (the day 30 days ago), null until the daily aggregation has counted it.
     *
     * @return array{dau: int, wau: int, mau: int, previousMau: ?int}
     */
    public function getActiveUsers(): array
    {
        $retention = $this->requestRepository->retentionSnapshot();
        $previousDay = AnalyticsCalendar::daysAgo(self::MAU_DAYS);
        $previous = $this->reportingQuery->activeUsersByDay($previousDay, $previousDay->modify('+1 day'));

        return [
            'dau' => $retention['dau'],
            'wau' => $retention['wau'],
            'mau' => $retention['mau'],
            'previousMau' => $previous[$previousDay->format('Y-m-d')]['mau'] ?? null,
        ];
    }

    /**
     * DAU, WAU and MAU per day for the last `$days` days including today. Past days come from the daily aggregates,
     * today from the raw rows; a past day without a counted value is null.
     */
    public function getUsageTrend(int $days = self::TREND_DAYS): UsageTrendDto
    {
        $today = AnalyticsCalendar::startOfToday();
        $from = $today->modify(sprintf('-%d days', $days - 1));
        $byDay = $this->reportingQuery->activeUsersByDay($from, $today);
        $live = $this->requestRepository->retentionSnapshot();

        $labels = $dau = $wau = $mau = [];
        for ($offset = $days - 1; $offset >= 0; --$offset) {
            $day = $today->modify(sprintf('-%d days', $offset));
            $labels[] = $day->format('d.m.');
            if (0 === $offset) {
                $dau[] = $live['dau'];
                $wau[] = $live['wau'];
                $mau[] = $live['mau'];

                continue;
            }

            $row = $byDay[$day->format('Y-m-d')] ?? null;
            $dau[] = $row['dau'] ?? null;
            $wau[] = $row['wau'] ?? null;
            $mau[] = $row['mau'] ?? null;
        }

        return new UsageTrendDto($labels, $dau, $wau, $mau);
    }
}
