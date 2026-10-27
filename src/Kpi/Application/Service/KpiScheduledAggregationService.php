<?php

declare(strict_types=1);

namespace App\Kpi\Application\Service;

use App\Kpi\Application\Contract\KpiScheduledAggregationRunnerInterface;
use App\Kpi\Application\DTO\KpiScheduledAggregationResult;
use Psr\Clock\ClockInterface;

/** @psalm-suppress UnusedClass Wired via KpiScheduledAggregationRunnerInterface autowiring. */
final readonly class KpiScheduledAggregationService implements KpiScheduledAggregationRunnerInterface
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private KpiAggregationService $aggregationService,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function run(): KpiScheduledAggregationResult
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now = $this->clock->now()->setTimezone($tz);
        $yesterday = $now->modify('yesterday');
        $today = $now->modify('today');

        $dates = [$yesterday, $today];
        $totalRows = 0;
        $daysWithData = 0;

        foreach ($dates as $date) {
            $count = $this->aggregationService->aggregateForDate($date);
            $totalRows += $count;
            if ($count > 0) {
                ++$daysWithData;
            }
        }

        return new KpiScheduledAggregationResult(
            dates: array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates),
            daysProcessed: \count($dates),
            totalRows: $totalRows,
            daysWithData: $daysWithData,
        );
    }
}
