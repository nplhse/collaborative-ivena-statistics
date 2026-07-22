<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellEdgeCasesTest extends AnalysisExplorerShellTestCase
{
    public function testMountDowngradesManipulatedHospitalAxisOnPublicScope(): void
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $state = $mapper->toStateArray($viewFactory->createDefault($filter));
        $state['query']['rows'] = ['dimension' => 'hospital', 'grain' => 'total'];

        $user = UserFactory::createOne(['username' => 'explorer-guard-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $state,
            'locale' => 'en',
        ])->actingAs($user);

        $render = $testComponent->render();

        self::assertSame('time', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertNotNull($testComponent->component()->configWarning);
    }

    public function testCancelEditClosesDrawerWithoutApplying(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');
        self::assertTrue($testComponent->component()->isEditOpen);

        $testComponent->call('cancelEdit');
        self::assertFalse($testComponent->component()->isEditOpen);
        self::assertSame('bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
    }

    public function testApplyEditWorksWhenDrawerWasNotOpenedViaOpenEdit(): void
    {
        $testComponent = $this->createShellComponent();
        $render = $testComponent->render();
        self::assertFalse($testComponent->component()->isEditOpen);

        $formName = $this->formName($render);
        $testComponent
            ->submitForm($this->formPayload($formName, ['chartType' => 'line']))
            ->call('applyEdit');

        self::assertSame('line', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertFalse($testComponent->component()->isEditOpen);
    }

    public function testFormChangeWithoutApplyDoesNotUpdateAppliedConfig(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent->submitForm($this->formPayload($formName, [
            'rowDimension' => 'time',
            'rowGrain' => 'year',
            'columnDimension' => 'urgency',
            'columnGrain' => 'total',
            'chartType' => 'line',
        ]));

        self::assertSame('bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertSame('month', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
    }

    public function testApplyEditShowsEmptyStateWhenNoData(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
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
            ->call('applyEdit')
            ->render();

        self::assertFalse($testComponent->component()->hasChart);
        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-chart-empty"]')->count());
        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-table-empty"]')->count());
    }

    public function testSetChartRowLimitUpdatesAppliedStateWithoutRerunningAnalysis(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $component = $testComponent->component();
        $revisionAfterLoad = $component->analysisRevision;
        $tableSnapshot = $component->table;

        $testComponent->call('setChartRowLimit', ['limit' => '5']);
        $testComponent->render();
        $component = $testComponent->component();

        self::assertGreaterThan($revisionAfterLoad, $component->analysisRevision);
        self::assertSame('5', $component->appliedConfigState['presentation']['chartRowLimit'] ?? null);
        self::assertSame($tableSnapshot, $component->table);
        self::assertTrue($component->hasUnsavedChanges);
    }
}
