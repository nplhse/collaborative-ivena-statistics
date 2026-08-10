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

final class AnalysisExplorerShellDispatchOpenEditTest extends AnalysisExplorerShellTestCase
{
    use EligibleBenchmarkScopeTrait;

    public function testOpenEditShowsConcreteDispatchAreaDetailWithoutRefresh(): void
    {
        $user = UserFactory::createOne(['username' => 'explorer-open-'.bin2hex(random_bytes(4))]);
        $scope = $this->seedEligibleBenchmarkScope($user, 'OpenDA');
        $dispatchAreaId = $scope['dispatchArea']->getId();
        self::assertNotNull($dispatchAreaId);

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::DispatchArea,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
            stateId: null,
            dispatchAreaId: $dispatchAreaId,
        );
        $defaultConfig = $viewFactory->createDefault($filter);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($defaultConfig),
            'locale' => 'de',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');
        $html = $testComponent->render()->toString();

        self::assertStringContainsString('OpenDADispatch', $html, 'openEdit should render concrete dispatch area in scopeDetail without requiring refresh');
        self::assertStringContainsString('name="', $html);
        // scopeDetail field should exist
        self::assertMatchesRegularExpression('/scopePeriod\[scopeDetail\]|scopePeriod_scopeDetail/', $html);
    }
}
