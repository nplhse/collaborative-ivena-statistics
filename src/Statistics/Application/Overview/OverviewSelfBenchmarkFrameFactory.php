<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\Overview\Dto\OverviewSelfBenchmarkFrameViewModel;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class OverviewSelfBenchmarkFrameFactory
{
    public function __construct(
        private OverviewComparisonPeriodResolver $comparisonPeriodResolver,
        private OverviewExecutiveKpiFactory $executiveKpiFactory,
        private OverviewSelfBenchmarkFactory $selfBenchmarkFactory,
        private OverviewHospitalInsightsProvider $hospitalInsightsProvider,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function build(
        Request $request,
        StatisticsContext $context,
        string $reportingPeriodLabel,
    ): OverviewSelfBenchmarkFrameViewModel {
        $comparison = $this->comparisonPeriodResolver->resolve($context->filter);
        $benchmarkReport = $this->selfBenchmarkFactory->build($context, $comparison);
        $benchmarkingUrl = $this->navigationUrlBuilder->build($request, 'app_stats_benchmarking');

        return new OverviewSelfBenchmarkFrameViewModel(
            $this->executiveKpiFactory->build($context, $benchmarkReport, $comparison),
            $this->hospitalInsightsProvider->build(
                $benchmarkReport,
                $comparison,
                $benchmarkingUrl,
                $reportingPeriodLabel,
            ),
            $benchmarkReport->suppressRatios,
            $benchmarkingUrl,
            $comparison,
        );
    }
}
