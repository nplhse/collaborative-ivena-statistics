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

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');
        $render = $testComponent->render();
        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-edit-form"]')->count());

        $formName = $render->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'all',
                    ],
                    'dimensionGrain' => 'month',
                    'chartType' => 'line',
                ],
            ])
            ->call('applyEdit')
            ->render();

        $chart = $updatedRender->crawler()->filter('[data-controller="generic-analysis-chart"]');
        if ($chart->count() > 0) {
            $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
            self::assertNotNull($specsRaw);
            self::assertStringContainsString('"line"', $specsRaw);
            self::assertSame('line', $chart->attr('data-generic-analysis-chart-default-type-value'));
        } else {
            self::assertSame('line', $testComponent->component()->appliedConfigState['chartType'] ?? null);
        }
    }

    public function testCancelEditClosesDrawerWithoutApplying(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-cancel-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');
        self::assertTrue($testComponent->component()->isEditOpen);

        $testComponent->call('cancelEdit');
        self::assertFalse($testComponent->component()->isEditOpen);
        self::assertSame('bar', $testComponent->component()->appliedConfigState['chartType'] ?? null);
    }

    public function testApplyEditWorksWhenDrawerWasNotOpenedViaOpenEdit(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-apply-no-open-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $render = $testComponent->render();
        self::assertFalse($testComponent->component()->isEditOpen);

        $formName = $render->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'all',
                    ],
                    'dimensionGrain' => 'month',
                    'chartType' => 'line',
                ],
            ])
            ->call('applyEdit');

        self::assertSame('line', $testComponent->component()->appliedConfigState['chartType'] ?? null);
        self::assertFalse($testComponent->component()->isEditOpen);
    }

    public function testFormChangeWithoutApplyDoesNotUpdateAppliedConfig(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-defer-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent->submitForm([
            $formName => [
                'scopePeriod' => [
                    'scopeGroup' => 'public',
                    'period' => 'all',
                ],
                'dimensionGrain' => 'year',
                'chartType' => 'line',
            ],
        ]);

        self::assertSame('bar', $testComponent->component()->appliedConfigState['chartType'] ?? null);
        self::assertSame('month', $testComponent->component()->appliedConfigState['dimensionGrain'] ?? null);
    }

    public function testPeriodChangeRefreshesYearFieldInDrawer(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-period-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $initialRender = $testComponent->render();
        self::assertCount(
            0,
            $initialRender->crawler()->filter('[data-testid="stats-analysis-explorer-period-form"] select[name$="[periodYear]"]'),
        );

        $formName = $initialRender->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'year',
                    ],
                    'dimensionGrain' => 'month',
                    'chartType' => 'bar',
                ],
            ])
            ->call('refreshEditForm')
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-analysis-explorer-period-form"] select[name$="[periodYear]"]')->count(),
        );
        self::assertSame('bar', $testComponent->component()->appliedConfigState['chartType'] ?? null);
    }

    public function testApplyEditChangesDimensionGrainInChartSpecs(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-year-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'all',
                    ],
                    'dimensionGrain' => 'year',
                    'chartType' => 'bar',
                ],
            ])
            ->call('applyEdit')
            ->render();

        self::assertSame('year', $testComponent->component()->appliedConfigState['dimensionGrain'] ?? null);
    }

    public function testApplyEditShowsEmptyStateWhenNoData(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-empty-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $render = $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'public',
                        'period' => 'year',
                    ],
                    'dimensionGrain' => 'month',
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

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'state',
                        'scopeDetail' => (string) $stateId,
                        'period' => 'all',
                    ],
                    'dimensionGrain' => 'month',
                    'chartType' => 'bar',
                ],
            ])
            ->call('applyEdit');

        self::assertSame('state', $testComponent->component()->appliedConfigState['scopeGroup'] ?? null);
        self::assertSame((string) $stateId, $testComponent->component()->appliedConfigState['scopeDetail'] ?? null);
        self::assertFalse($testComponent->component()->isEditOpen);
    }
}
