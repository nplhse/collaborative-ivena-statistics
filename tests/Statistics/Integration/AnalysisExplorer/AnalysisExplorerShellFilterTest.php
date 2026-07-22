<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellFilterTest extends AnalysisExplorerShellTestCase
{
    use EligibleBenchmarkScopeTrait;

    public function testApplyEditPersistsAnalysisFiltersInAppliedState(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());

        $testComponent
            ->submitForm($this->formPayload($formName, [
                'filterUrgency' => '1',
                'filterAgeGroup' => 'under_18',
            ]))
            ->call('applyEdit');

        $filters = $testComponent->component()->appliedConfigState['query']['filters'] ?? [];
        self::assertCount(2, $filters);
        self::assertSame('urgency', $filters[0]['dimensionKey']);
        self::assertSame(1, $filters[0]['value']);
        self::assertSame('age_group', $filters[1]['dimensionKey']);
        self::assertSame('under_18', $filters[1]['value']);
    }

    public function testRefreshEditFormPreservesSubmittedFilters(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());

        $testComponent
            ->submitForm($this->formPayload($formName, [
                'filterUrgency' => '2',
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
            ]))
            ->call('refreshEditForm')
            ->call('applyEdit');

        $filters = $testComponent->component()->appliedConfigState['query']['filters'] ?? [];
        self::assertCount(1, $filters);
        self::assertSame('urgency', $filters[0]['dimensionKey']);
        self::assertSame(2, $filters[0]['value']);
    }

    public function testPeriodChangeRefreshesYearFieldInDrawer(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $initialRender = $testComponent->render();
        self::assertCount(
            0,
            $initialRender->crawler()->filter('[data-testid="stats-analysis-explorer-period-form"] select[name$="[periodYear]"]'),
        );

        $formName = $this->formName($initialRender);
        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'year',
                    ],
                    'rowDimension' => 'time',
                    'rowGrain' => 'month',
                    'columnDimension' => '',
                    'columnGrain' => 'total',
                    'metric' => 'allocation_count',
                    'chartType' => 'bar',
                ],
            ])
            ->call('refreshEditForm')
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-analysis-explorer-period-form"] select[name$="[periodYear]"]')->count(),
        );
        self::assertSame('bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
    }

    public function testApplyEditPreservesStateScope(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-state-'.bin2hex(random_bytes(4))]);
        $scope = $this->seedEligibleBenchmarkScope($user, 'ExplorerState');
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);
        $stateId = $scope['state']->getId();
        self::assertNotNull($stateId);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'state',
                        'scopeDetail' => (string) $stateId,
                        'period' => 'all',
                    ],
                    'rowDimension' => 'time',
                    'rowGrain' => 'month',
                    'columnDimension' => '',
                    'columnGrain' => 'total',
                    'metric' => 'allocation_count',
                    'chartType' => 'bar',
                ],
            ])
            ->call('applyEdit');

        self::assertSame('state', $testComponent->component()->appliedConfigState['query']['scope']['group'] ?? null);
        self::assertSame((string) $stateId, $testComponent->component()->appliedConfigState['query']['scope']['detail'] ?? null);
        self::assertFalse($testComponent->component()->isEditOpen);
    }
}
