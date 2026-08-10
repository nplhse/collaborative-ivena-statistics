<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerAnalysisSummaryFactoryTranslationTest extends KernelTestCase
{
    public function testEnglishAndGermanTemplatesProduceReadableSummaries(): void
    {
        self::bootKernel();

        /** @var ExplorerAnalysisSummaryFactory $factory */
        $factory = self::getContainer()->get(ExplorerAnalysisSummaryFactory::class);

        $config = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(ChartPresentationType::Bar),
            title: 'Test',
        );

        $english = $factory->create($config, locale: 'en')->plainText();
        $german = $factory->create($config, locale: 'de')->plainText();

        self::assertStringContainsString('Allocations', $english);
        self::assertStringContainsString('all hospitals', $english);
        self::assertStringContainsString('Last 12 months', $english);
        self::assertStringContainsString('monthly', $english);

        self::assertStringContainsString('Zuweisungen', $german);
        self::assertStringContainsString('alle Krankenhäuser', $german);
        self::assertStringContainsString('Letzte 12 Monate', $german);
        self::assertStringContainsString('monatlich', $german);
    }
}
