<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerSelectionSummaryPresenter;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisFamily;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerSelectionSummaryPresenterTest extends KernelTestCase
{
    public function testPresentBuildsFamilyStructureScopePeriodAndFilters(): void
    {
        self::bootKernel();
        /** @var ExplorerSelectionSummaryPresenter $presenter */
        $presenter = self::getContainer()->get(ExplorerSelectionSummaryPresenter::class);

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: new AnalysisAxisRef(AnalysisDimensionKey::Time, AnalysisDimensionGrain::Month),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(ChartPresentationType::Line),
            title: 'Allocations over time',
            filters: [
                new AnalysisFilter('resus', AnalysisFilterOperator::Equals, 1),
            ],
        );

        $summary = $presenter->present($config, AnalysisFamily::TimeSeries->value, null, 'en');

        self::assertSame('Time series', $summary['family']['value'] ?? null);
        self::assertNotSame('', $summary['structure'][0]['value'] ?? '');
        self::assertNotSame('', $summary['structure'][1]['value'] ?? '');
        self::assertSame('scope', $summary['scope']['key']);
        self::assertSame('period', $summary['period']['key']);
        self::assertCount(1, $summary['filters']);
        self::assertTrue($summary['filters'][0]['opensEdit']);
    }
}
