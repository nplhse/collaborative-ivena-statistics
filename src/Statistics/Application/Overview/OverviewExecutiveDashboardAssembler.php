<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewExecutiveDashboardAssembler
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private OverviewPeriodComparisonService $periodComparison,
        private OverviewExecutiveKpiFactory $executiveKpiFactory,
        private OverviewSelfBenchmarkFactory $selfBenchmarkFactory,
        private OverviewBenchmarkSummaryFactory $benchmarkSummaryFactory,
        private OverviewHospitalInsightsProvider $hospitalInsightsProvider,
        private OverviewIndicationSectionFactory $indicationSectionFactory,
        private OverviewPopulationProfileFactory $populationProfileFactory,
        private OverviewChartsFactory $chartsFactory,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function build(
        Request $request,
        StatisticsContext $context,
        OverviewDashboardMetricsResult $metrics,
        string $reportingPeriodLabel,
    ): OverviewExecutiveDashboardViewModel {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->scopeResolver->resolveCriteria($context)->hospitalIds;
        $previousScopedTotal = $this->periodComparison->fetchPreviousScopedTotal($context);

        $benchmarkReport = $this->selfBenchmarkFactory->build($context);
        $indicationSection = $this->indicationSectionFactory->build($request, $context, $metrics->scopedTotal);
        $benchmarkingUrl = $this->navigationUrlBuilder->build($request, 'app_stats_benchmarking');

        return new OverviewExecutiveDashboardViewModel(
            $this->executiveKpiFactory->build($context, $benchmarkReport),
            $this->hospitalInsightsProvider->build(
                $context,
                $benchmarkReport,
                $metrics->scopedTotal,
                $previousScopedTotal,
                $benchmarkingUrl,
                $reportingPeriodLabel,
            ),
            $indicationSection,
            $this->benchmarkSummaryFactory->buildScorecard($benchmarkReport),
            $this->benchmarkSummaryFactory->buildDeviations($request, $benchmarkReport),
            $benchmarkReport->hasInsufficientData,
            $benchmarkReport->suppressRatios,
            $benchmarkingUrl,
            $this->populationProfileFactory->build($metrics),
            $this->chartsFactory->build(
                OverviewQueryCriteria::fromPeriodBounds($bounds, $hospitalIds),
                $metrics,
                $benchmarkReport,
            ),
            [],
        );
    }
}
