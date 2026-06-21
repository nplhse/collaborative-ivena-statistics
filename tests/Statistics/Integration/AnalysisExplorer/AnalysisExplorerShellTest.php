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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerShellTest extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;
    use EligibleBenchmarkScopeTrait;

    public function testApplyEditChangesChartType(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());

        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, ['chartType' => 'line']))
            ->call('applyEdit')
            ->render();

        $chart = $updatedRender->crawler()->filter('[data-controller="generic-analysis-chart"]');
        if ($chart->count() > 0) {
            $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
            self::assertNotNull($specsRaw);
            self::assertStringContainsString('"line"', $specsRaw);
            self::assertSame('line', $chart->attr('data-generic-analysis-chart-default-type-value'));
        } else {
            self::assertSame('line', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        }
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
            'timeGrain' => 'year',
            'chartType' => 'line',
        ]));

        self::assertSame('bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertSame('month', $testComponent->component()->appliedConfigState['query']['grain'] ?? null);
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
                    'dimension' => 'time',
                    'metric' => 'allocation_count',
                    'timeGrain' => 'month',
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

    public function testApplyEditChangesTimeGrainInAppliedState(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, ['timeGrain' => 'year']))
            ->call('applyEdit');

        self::assertSame('year', $testComponent->component()->appliedConfigState['query']['grain'] ?? null);
    }

    public function testApplyEditChangesDimensionToGender(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'dimension' => 'gender',
                'timeGrain' => null,
            ]))
            ->call('applyEdit');

        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['dimension'] ?? null);
        self::assertNull($testComponent->component()->appliedConfigState['query']['grain'] ?? null);
    }

    public function testDimensionChangeHidesTimeGrainField(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
            ->submitForm($this->formPayload($formName, ['dimension' => 'urgency', 'timeGrain' => null]))
            ->call('refreshEditForm')
            ->render();

        self::assertCount(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-time-grain-field"]'));
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
                    'dimension' => 'time',
                    'metric' => 'allocation_count',
                    'timeGrain' => 'month',
                    'chartType' => 'bar',
                ],
            ])
            ->call('applyEdit')
            ->render();

        self::assertFalse($testComponent->component()->hasChart);
        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-chart-empty"]')->count());
        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-table-empty"]')->count());
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
                    'dimension' => 'time',
                    'metric' => 'allocation_count',
                    'timeGrain' => 'month',
                    'chartType' => 'bar',
                ],
            ])
            ->call('applyEdit');

        self::assertSame('state', $testComponent->component()->appliedConfigState['query']['scope']['group'] ?? null);
        self::assertSame((string) $stateId, $testComponent->component()->appliedConfigState['query']['scope']['detail'] ?? null);
        self::assertFalse($testComponent->component()->isEditOpen);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, array<string, mixed>>
     */
    private function formPayload(string $formName, array $overrides = []): array
    {
        return [
            $formName => array_merge([
                'scopePeriod' => [
                    'scopeGroup' => 'public',
                    'period' => 'all',
                ],
                'dimension' => 'time',
                'metric' => 'allocation_count',
                'timeGrain' => 'month',
                'chartType' => 'bar',
            ], $overrides),
        ];
    }

    private function formName(object $render): string
    {
        $formName = $render->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        return $formName;
    }

    private function createShellComponent(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        $user = UserFactory::createOne(['username' => 'explorer-live-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        return $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);
    }
}
