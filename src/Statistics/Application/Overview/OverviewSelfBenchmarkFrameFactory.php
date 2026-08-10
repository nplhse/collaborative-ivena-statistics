<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\Overview\Dto\OverviewSelfBenchmarkFrameViewModel;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewSelfBenchmarkFrameFactory
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private OverviewPeriodComparisonService $periodComparison,
        private OverviewExecutiveKpiFactory $executiveKpiFactory,
        private OverviewSelfBenchmarkFactory $selfBenchmarkFactory,
        private OverviewHospitalInsightsProvider $hospitalInsightsProvider,
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function build(
        Request $request,
        StatisticsContext $context,
        string $reportingPeriodLabel,
    ): OverviewSelfBenchmarkFrameViewModel {
        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $hospitalIds = $this->scopeResolver->resolveCriteria($context)->hospitalIds;
        $scopedTotal = $this->timeSeriesQuery->countCreatedInPeriod(
            $bounds->from,
            $bounds->toExclusive,
            $hospitalIds,
        );
        $previousScopedTotal = $this->periodComparison->fetchPreviousScopedTotal($context);
        $benchmarkReport = $this->selfBenchmarkFactory->build($context);
        $benchmarkingUrl = $this->navigationUrlBuilder->build($request, 'app_stats_benchmarking');

        return new OverviewSelfBenchmarkFrameViewModel(
            $this->executiveKpiFactory->build($context, $benchmarkReport),
            $this->hospitalInsightsProvider->build(
                $context,
                $benchmarkReport,
                $scopedTotal,
                $previousScopedTotal,
                $benchmarkingUrl,
                $reportingPeriodLabel,
            ),
            $benchmarkReport->suppressRatios,
            $benchmarkingUrl,
        );
    }
}
