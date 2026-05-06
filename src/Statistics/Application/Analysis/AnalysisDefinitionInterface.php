<?php

declare(strict_types=1);

namespace App\Statistics\Application\Analysis;

use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;

/**
 * Configurable analysis: one widget output (chart or table) per filter state.
 *
 * @phpstan-type AnalysisView 'chart'|'table'
 * @phpstan-type AnalysisChartType 'line'|'bar'
 */
interface AnalysisDefinitionInterface
{
    public function key(): string;

    /** XLIFF resname for UI labels (dropdown, breadcrumb). */
    public function labelTranslationKey(): string;

    public function supports(StatisticsContext $context): bool;

    public function isPivotLike(): bool;

    /**
     * @param AnalysisView           $view
     * @param AnalysisChartType      $chartType    only used when $view === 'chart'
     * @param StatisticsChartMeasure $chartMeasure for dimension=resources and bar chart: absolute | share of month total (features: absolute only for now)
     */
    public function build(
        StatisticsContext $context,
        string $view,
        string $chartType,
        StatisticsAnalysisDimension $dimension,
        StatisticsChartMeasure $chartMeasure = StatisticsChartMeasure::Absolute,
    ): StatisticWidget;

    public function supportsDimensionSelector(): bool;

    public function supportsChartMeasureSelector(
        StatisticsAnalysisDimension $dimension,
        string $view,
        string $chartType,
    ): bool;
}
