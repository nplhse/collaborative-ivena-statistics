<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerAnalysisQueryFactoryTest extends KernelTestCase
{
    public function testCreateResolvesRollingPeriodForAllFilter(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(ExplorerAnalysisQueryFactory::class);
        \assert($factory instanceof ExplorerAnalysisQueryFactory);

        $query = $factory->create(
            new AnalysisViewConfig(
                dataSourceKey: AnalysisDataSourceKey::Allocations,
                metricKey: AnalysisMetricKey::AllocationCount,
                dimensionKey: AnalysisDimensionKey::Time,
                timeGrain: AnalysisDimensionGrain::Month,
                statisticsFilter: new StatisticsFilter(
                    scope: StatisticsFilterScope::Public,
                    hospitalId: null,
                    cohortType: null,
                    period: StatisticsFilterPeriod::All,
                ),
                presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
                title: 'Allocations over time',
            ),
            null,
        );

        self::assertSame(StatisticsPeriod::overviewPeriodStart()->format('Y-m-d'), $query->periodBounds->from?->format('Y-m-d'));
        self::assertSame(AnalysisDimensionKey::Time, $query->dimensionKey);
    }
}
