<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Twig\Components\EmbeddedAnalysisChart;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AnalysisExecutionTest extends KernelTestCase
{
    public function testForbiddenHospitalScopeDoesNotRunThatHospitalsData(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Hospital,
            hospitalId: 999999,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));

        $result = self::getContainer()->get(AnalysisExecution::class)->execute($config, $user);

        self::assertSame('scope_forbidden', $result->emptyReason);
        self::assertSame([], $result->result->rows);
        self::assertFalse($result->hasChart);

        $anonymous = self::getContainer()->get(AnalysisExecution::class)->execute($config, null);
        self::assertSame('scope_forbidden', $anonymous->emptyReason);
        self::assertSame([], $anonymous->result->rows);
    }

    public function testMyHospitalsScopeResolvesForTheCurrentViewer(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::MyHospitals,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        ));

        $query = self::getContainer()->get(ExplorerAnalysisQueryFactory::class)->create($config, $user);

        self::assertSame([], $query->scopeCriteria->hospitalIds);
    }

    public function testEmbeddedAgeGroupChartUsesAllocationCount(): void
    {
        self::bootKernel();
        $component = self::getContainer()->get(EmbeddedAnalysisChart::class);
        $component->statisticsFilter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $view = $component->view();
        $config = new \ReflectionMethod($component, 'config')->invoke($component);

        self::assertSame(AnalysisDimensionKey::AgeGroup, $config->rowAxis->dimensionKey);
        self::assertSame(AnalysisMetricKey::AllocationCount, $config->visualMetricKey);
        self::assertSame([AnalysisMetricKey::AllocationCount], $config->metricKeys);
        self::assertSame(ChartPresentationType::Bar, $config->presentation->chartType);
        self::assertNull($config->columnAxis);
        self::assertStringContainsString('age-group-distribution', $view['explorerUrl']);
        self::assertStringContainsString('usePageScope=1', $view['explorerUrl']);
        self::assertSame('bar', $view['defaultChartType']);
    }
}
