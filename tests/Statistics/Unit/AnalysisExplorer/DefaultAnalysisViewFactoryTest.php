<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerTitleFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DefaultAnalysisViewFactoryTest extends TestCase
{
    public function testCreateDefaultReturnsAllocationsOverTimeConfig(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->with('stats.analysis_explorer.allocations_over_time')->willReturn('Allocations over time');

        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $factory = new DefaultAnalysisViewFactory(new ExplorerTitleFactory($translator));
        $config = $factory->createDefault($filter);

        self::assertSame('allocations', $config->dataSourceKey->value);
        self::assertSame('allocation_count', $config->visualMetricKey->value);
        self::assertSame([AnalysisMetricKey::AllocationCount], $config->metricKeys);
        self::assertSame(AnalysisDimensionKey::Time, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisDimensionGrain::Month, $config->rowAxis->resolvedGrain());
        self::assertNull($config->columnAxis);
        self::assertSame($filter, $config->statisticsFilter);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertSame('Allocations over time', $config->title);
    }
}
