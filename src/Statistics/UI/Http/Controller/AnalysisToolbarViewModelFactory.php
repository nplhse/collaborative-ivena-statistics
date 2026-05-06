<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisToolbarViewModelFactory
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    public function create(
        Request $request,
        AnalysisDefinitionInterface $activeDefinition,
        AnalysisRequestModel $analysisRequest,
    ): AnalysisToolbarViewModel {
        return new AnalysisToolbarViewModel(
            $activeDefinition->isPivotLike(),
            $activeDefinition->supportsDimensionSelector(),
            $activeDefinition->supportsChartMeasureSelector(
                $analysisRequest->dimension,
                $analysisRequest->view,
                $analysisRequest->chartType,
            ),
            $analysisRequest->view,
            $analysisRequest->chartType,
            $analysisRequest->dimension->value,
            $analysisRequest->chartMeasure->value,
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['view' => 'chart']),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['view' => 'table']),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'line']),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'bar']),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Total->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Gender->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Urgency->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Resources->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Features->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['chart_measure' => StatisticsChartMeasure::Absolute->value]),
            $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', ['chart_measure' => StatisticsChartMeasure::Share->value]),
        );
    }
}
