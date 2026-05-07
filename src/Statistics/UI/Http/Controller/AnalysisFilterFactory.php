<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisFilterFactory
{
    public function __construct(
        private AnalysisKeyAliasResolver $analysisKeyAliasResolver,
    ) {
    }

    public function fromRequest(Request $request): AnalysisFilterInput
    {
        $requestedAnalysis = $this->analysisKeyAliasResolver->resolve(
            $request->query->getString(StatisticsQueryKeys::ANALYSIS, 'allocations_by_month')
        );

        $view = $request->query->getString(StatisticsQueryKeys::VIEW, 'chart');
        if (!\in_array($view, ['chart', 'table'], true)) {
            $view = 'chart';
        }

        $chartType = $request->query->getString(StatisticsQueryKeys::CHART, 'bar');
        if (!\in_array($chartType, ['line', 'bar'], true)) {
            $chartType = 'bar';
        }

        $dimension = StatisticsAnalysisDimension::tryFrom($request->query->getString(StatisticsQueryKeys::DIMENSION))
            ?? StatisticsAnalysisDimension::Total;

        $chartMeasure = StatisticsChartMeasure::fromQueryValue($request->query->getString(StatisticsQueryKeys::CHART_MEASURE));
        if (StatisticsAnalysisDimension::Features === $dimension && StatisticsChartMeasure::Share === $chartMeasure) {
            $chartMeasure = StatisticsChartMeasure::Absolute;
        }

        return new AnalysisFilterInput(
            $requestedAnalysis,
            $view,
            $chartType,
            $dimension,
            $chartMeasure,
            $request->query->getString(StatisticsQueryKeys::ROWS),
            $request->query->getString(StatisticsQueryKeys::COLS),
            $request->query->getString(StatisticsQueryKeys::MEASURE),
        );
    }
}
