<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\Monthly\Dto;

use App\Statistics\Application\Insights\HospitalInsight;

final readonly class MonthlyReportView
{
    /**
     * @param list<MonthlyReportSegment> $urgencySegments
     * @param list<MonthlyReportSegment> $genderSegments
     * @param list<MonthlyReportTopRow>  $topDiagnoses
     * @param list<MonthlyReportTopRow>  $topOccasions
     * @param list<MonthlyReportTopRow>  $topDepartments
     * @param list<HospitalInsight>      $insights
     * @param array{
     *     chartType: string,
     *     labels: list<string>,
     *     counts: list<int>,
     *     valueLabel?: string,
     *     xAxisLabel?: string,
     *     yAxisLabel?: string
     * } $dailyChart
     */
    public function __construct(
        public string $periodLabel,
        public int $year,
        public int $month,
        public bool $hasData,
        public int $allocationCount,
        public ?float $allocationMomPercent,
        public ?float $allocationYoyPercent,
        public float $withPhysicianPercent,
        public ?float $withPhysicianMomPercent,
        public ?float $medianTransportMinutes,
        public ?float $medianTransportMomMinutes,
        public float $resusPercent,
        public ?float $resusMomPercent,
        public array $urgencySegments,
        public array $genderSegments,
        public array $topDiagnoses,
        public array $topOccasions,
        public array $topDepartments,
        public array $insights,
        public array $dailyChart,
        public string $importCreateUrl,
        public string $dashboardUrl,
        public string $benchmarkingUrl,
        public string $previousMonthLabel,
        public int $previousYear,
        public int $previousMonth,
        public ?string $nextMonthLabel,
        public ?int $nextYear,
        public ?int $nextMonth,
        public bool $nextEnabled,
    ) {
    }
}
