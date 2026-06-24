<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerFilterBadgePresenter;
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
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExplorerFilterBadgePresenterIntegrationTest extends KernelTestCase
{
    use Factories;

    private ExplorerFilterBadgePresenter $presenter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->presenter = self::getContainer()->get(ExplorerFilterBadgePresenter::class);
    }

    public function testPresentsLabelsForSeededEntitiesAndBooleanFilters(): void
    {
        UserFactory::createOne();
        $department = DepartmentFactory::createOne(['name' => 'Cardiology']);
        $speciality = SpecialityFactory::createOne(['name' => 'Emergency']);
        $assignment = AssignmentFactory::createOne(['name' => 'Primary transport']);

        $departmentId = $department->getId();
        $specialityId = $speciality->getId();
        $assignmentId = $assignment->getId();
        self::assertNotNull($departmentId);
        self::assertNotNull($specialityId);
        self::assertNotNull($assignmentId);

        $badges = $this->presenter->present($this->viewConfig([
            new AnalysisFilter('department', AnalysisFilterOperator::In, [$departmentId]),
            new AnalysisFilter('speciality', AnalysisFilterOperator::In, [$specialityId]),
            new AnalysisFilter('assignment', AnalysisFilterOperator::Equals, $assignmentId),
            new AnalysisFilter('urgency', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('gender', AnalysisFilterOperator::Equals, 2),
            new AnalysisFilter('age_group', AnalysisFilterOperator::Equals, StatisticsAgeGroupFilter::UNDER_18),
            new AnalysisFilter('resus', AnalysisFilterOperator::Equals, 1),
            new AnalysisFilter('cpr', AnalysisFilterOperator::Equals, 0),
            new AnalysisFilter('ventilation', AnalysisFilterOperator::Equals, [1]),
        ]));

        self::assertSame('Department', $badges[0]['label']);
        self::assertSame('Cardiology', $badges[0]['value']);
        self::assertSame('Female', $badges[4]['value']);
        self::assertSame('Yes', $badges[6]['value']);
        self::assertSame('No', $badges[7]['value']);
    }

    public function testFallsBackForUnknownDimensionAndMissingChoices(): void
    {
        $badges = $this->presenter->present($this->viewConfig([
            new AnalysisFilter('custom_metric', AnalysisFilterOperator::Equals, ['a', 'b']),
            new AnalysisFilter('department', AnalysisFilterOperator::In, [99]),
        ]));

        self::assertSame('custom_metric', $badges[0]['label']);
        self::assertSame('a, b', $badges[0]['value']);
        self::assertSame('99', $badges[1]['value']);
    }

    /**
     * @param list<AnalysisFilter> $filters
     */
    private function viewConfig(array $filters): AnalysisViewConfig
    {
        return new AnalysisViewConfig(
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
            title: 'Filtered view',
            filters: $filters,
        );
    }
}
