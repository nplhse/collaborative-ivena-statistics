<?php

declare(strict_types=1);

namespace App\Engagement\Application\Dto;

final readonly class MonthlyReminderContent
{
    /**
     * @param list<MonthlyReminderChartBar> $chartBars
     * @param list<MonthlyReminderSegment>  $urgencySegments
     * @param list<MonthlyReminderSegment>  $genderSegments
     * @param list<MonthlyReminderInsight>  $insights
     * @param array<string, string>         $submissionMonths Y-m => success|failed|missing
     */
    public function __construct(
        public string $hospitalName,
        public string $reportingPeriodLabel,
        public string $uploadMonthLabel,
        public string $preheader,
        public bool $isPersonalized,
        public int $allocationCount,
        public ?float $allocationMomPercent,
        public string $lastImportLabel,
        public bool $lastImportStale,
        public float $withPhysicianPercent,
        public ?float $withPhysicianBaselineDeltaPp,
        public ?string $baselinePeriodLabel,
        public float $medianTransportMinutes,
        public ?float $medianTransportBaselineDeltaMinutes,
        public string $trendSummary,
        public array $chartBars,
        public array $urgencySegments,
        public ?string $urgencyBenchmarkNote,
        public array $genderSegments,
        public array $insights,
        public int $submissionMonthsCompleted,
        public int $submissionMonthsTotal,
        public int $submissionProgressPercent,
        public array $submissionMonths,
        public ?string $longestSubmissionGapLabel,
        public string $importCreateUrl,
        public string $statisticsDashboardUrl,
        public string $benchmarkingUrl,
        public string $notificationsSettingsUrl,
        public ?int $platformAllocationCount = null,
        public ?float $platformAllocationMomPercent = null,
        public ?int $platformActiveHospitals = null,
        public ?int $platformImportsLastMonth = null,
    ) {
    }
}
