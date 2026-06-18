<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\Insights\HospitalInsight;
use App\Statistics\Application\Overview\Dto\OverviewBenchmarkDeviation;
use App\Statistics\Application\Overview\Dto\OverviewBenchmarkScorecardItem;
use App\Statistics\Application\Overview\Dto\OverviewChartsViewModel;
use App\Statistics\Application\Overview\Dto\OverviewDistributionRow;
use App\Statistics\Application\Overview\Dto\OverviewDistributionSegment;
use App\Statistics\Application\Overview\Dto\OverviewIndicationRow;
use App\Statistics\Application\Overview\Dto\OverviewTopReportCard;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;

final readonly class OverviewExecutiveDashboardViewModel
{
    /**
     * @param list<BenchmarkMetric>                                                                                                $kpiMetrics
     * @param list<HospitalInsight>                                                                                                $hospitalInsights
     * @param array{rows: list<OverviewIndicationRow>, donut: array{labels: list<string>, values: list<int>}, insightsUrl: string} $indication
     * @param list<OverviewBenchmarkScorecardItem>                                                                                 $benchmarkScorecard
     * @param array{positive: list<OverviewBenchmarkDeviation>, negative: list<OverviewBenchmarkDeviation>}                        $benchmarkDeviations
     * @param array{
     *     gender: array{titleTranslationKey: string, segments: list<OverviewDistributionSegment>},
     *     urgency: array{titleTranslationKey: string, segments: list<OverviewDistributionSegment>},
     *     age: list<OverviewDistributionRow>
     * }                                                                                                            $population
     * @param list<OverviewTopReportCard> $topReportCards
     */
    public function __construct(
        public array $kpiMetrics,
        public array $hospitalInsights,
        public array $indication,
        public array $benchmarkScorecard,
        public array $benchmarkDeviations,
        public bool $benchmarkInsufficientData,
        public bool $benchmarkSuppressRatios,
        public string $benchmarkingUrl,
        public array $population,
        public OverviewChartsViewModel $charts,
        public array $topReportCards,
    ) {
    }
}
