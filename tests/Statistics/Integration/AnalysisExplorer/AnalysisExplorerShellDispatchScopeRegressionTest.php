<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellDispatchScopeRegressionTest extends AnalysisExplorerShellTestCase
{
    use EligibleBenchmarkScopeTrait;

    public function testDispatchAreaChoicesAreNonEmptyAndApplyPersistsDetail(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-da-'.bin2hex(random_bytes(4))]);
        $scope = $this->seedEligibleBenchmarkScope($user, 'ExplorerDA');
        $dispatchAreaId = $scope['dispatchArea']->getId();
        self::assertNotNull($dispatchAreaId);

        $provider = self::getContainer()->get(StatisticsFilterFormChoiceProvider::class);
        $details = $provider->scopeDetailChoices(
            'dispatch_area',
            $user,
            StatisticsFilterSide::Primary,
            'de',
            StatisticsFilterScopeChoicePolicy::AllocationStatistics,
        );
        self::assertArrayHasKey((string) $dispatchAreaId, $details, 'Expected batch name lookup to resolve dispatch area');
        self::assertSame('ExplorerDADispatch', $details[(string) $dispatchAreaId]);

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
            'locale' => 'de',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());

        // Simulate user selecting only scopeGroup (no scopeDetail yet) then refresh
        $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'dispatch_area',
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
            ->call('refreshEditForm');

        $html = $testComponent->render()->toString();
        self::assertStringContainsString('ExplorerDADispatch', $html, 'Drawer should show concrete dispatch area name after refresh');

        $testComponent
            ->submitForm([
                $formName => [
                    'scopePeriod' => [
                        'scopeGroup' => 'dispatch_area',
                        'scopeDetail' => (string) $dispatchAreaId,
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

        self::assertSame('dispatch_area', $testComponent->component()->appliedConfigState['query']['scope']['group'] ?? null);
        self::assertSame((string) $dispatchAreaId, $testComponent->component()->appliedConfigState['query']['scope']['detail'] ?? null);
    }
}
