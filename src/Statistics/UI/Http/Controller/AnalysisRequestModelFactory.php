<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisRequestModelFactory
{
    public function __construct(
        private AnalysisKeyAliasResolver $analysisKeyAliasResolver,
    ) {
    }

    public function fromRequest(Request $request): AnalysisRequestModel
    {
        $requestedAnalysis = $this->analysisKeyAliasResolver->resolve(
            $request->query->getString('analysis', 'allocations_by_month')
        );

        $view = $request->query->getString('view', 'chart');
        if (!\in_array($view, ['chart', 'table'], true)) {
            $view = 'chart';
        }

        $chartType = $request->query->getString('chart', 'bar');
        if (!\in_array($chartType, ['line', 'bar'], true)) {
            $chartType = 'bar';
        }

        $dimension = StatisticsAnalysisDimension::tryFrom($request->query->getString('dimension'))
            ?? StatisticsAnalysisDimension::Total;

        $chartMeasure = StatisticsChartMeasure::fromQueryValue($request->query->getString('chart_measure'));
        if (StatisticsAnalysisDimension::Features === $dimension && StatisticsChartMeasure::Share === $chartMeasure) {
            $chartMeasure = StatisticsChartMeasure::Absolute;
        }

        return new AnalysisRequestModel(
            $requestedAnalysis,
            $view,
            $chartType,
            $dimension,
            $chartMeasure,
            $request->query->getString('rows'),
            $request->query->getString('cols'),
            $request->query->getString('measure'),
            $request->query->getString('comparison_scope'),
            $request->query->getString('comparison_period'),
            $request->query->has('comparison_year') ? $request->query->getInt('comparison_year') : null,
            $request->query->has('comparison_month') ? $request->query->getInt('comparison_month') : null,
        );
    }
}
