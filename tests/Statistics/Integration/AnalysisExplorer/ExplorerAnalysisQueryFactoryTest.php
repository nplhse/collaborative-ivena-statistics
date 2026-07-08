<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
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
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExplorerAnalysisQueryFactoryTest extends KernelTestCase
{
    use Factories;

    public function testCreateResolvesRollingPeriodForAllFilter(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(ExplorerAnalysisQueryFactory::class);
        \assert($factory instanceof ExplorerAnalysisQueryFactory);

        $query = $factory->create(
            new AnalysisViewConfig(
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
                presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
                title: 'Allocations over time',
            ),
            null,
        );

        self::assertSame(StatisticsPeriod::overviewPeriodStart()->format('Y-m-d'), $query->periodBounds->from?->format('Y-m-d'));
        self::assertSame(AnalysisDimensionKey::Time, $query->rowAxis->dimensionKey);
    }

    public function testCreateExpandsIndicationGroupFilterForQueryExecution(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(ExplorerAnalysisQueryFactory::class);
        \assert($factory instanceof ExplorerAnalysisQueryFactory);

        UserFactory::createOne();
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Chest pain', 'code' => 101]);
        $group = IndicationGroupFactory::createOne(['name' => 'Cardiac group']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();
        $groupId = $group->getId();
        $indicationId = $indication->getId();
        self::assertNotNull($groupId);
        self::assertNotNull($indicationId);

        $query = $factory->create(
            new AnalysisViewConfig(
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
                presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
                title: 'Filtered allocations',
                filters: [
                    new AnalysisFilter('indication_group', AnalysisFilterOperator::Equals, $groupId),
                ],
            ),
            null,
        );

        self::assertCount(1, $query->filters);
        self::assertSame('indication', $query->filters[0]->dimensionKey);
        self::assertSame([$indicationId], $query->filters[0]->value);
    }
}
