<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\AnalysisExplorerTestSupport;
use PHPUnit\Framework\TestCase;

abstract class ExplorerResultsTablePresenterTestCase extends TestCase
{
    use AnalysisExplorerTestSupport;

    protected function publicStatisticsFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>, 2: ?string, 3: ?string, 4: string}>
     */
    protected function boxPlotColumnTranslations(): array
    {
        return [
            ['stats.analysis_explorer.box_plot.column.distribution_min', [], 'statistics', null, 'Min'],
            ['stats.analysis_explorer.box_plot.column.distribution_p25', [], 'statistics', null, 'P25'],
            ['stats.analysis_explorer.box_plot.column.distribution_median', [], 'statistics', null, 'Median'],
            ['stats.analysis_explorer.box_plot.column.distribution_p75', [], 'statistics', null, 'P75'],
            ['stats.analysis_explorer.box_plot.column.distribution_max', [], 'statistics', null, 'Max'],
        ];
    }
}
