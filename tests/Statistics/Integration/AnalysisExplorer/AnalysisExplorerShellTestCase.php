<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\DefaultHospitalsAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
abstract class AnalysisExplorerShellTestCase extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, array<string, mixed>>
     */
    protected function formPayload(string $formName, array $overrides = []): array
    {
        return [
            $formName => array_merge([
                'scopePeriod' => [
                    'scopeGroup' => 'public',
                    'period' => 'all',
                ],
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => '',
                'metric' => 'allocation_count',
                'chartType' => 'bar',
            ], $overrides),
        ];
    }

    protected function formName(object $render): string
    {
        $formName = $render->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        return $formName;
    }

    protected function createShellComponent(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
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
            'libraryUrl' => '/statistics/analysis/library',
        ])->actingAs($user);
    }

    protected function createHospitalsShellComponent(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        $user = UserFactory::createOne(['username' => 'explorer-hospitals-'.bin2hex(random_bytes(4))]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultHospitalsAnalysisViewFactory::class);
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
            'libraryUrl' => '/statistics/analysis/library',
        ])->actingAs($user);
    }

    /**
     * @return array{0: \Symfony\UX\LiveComponent\Test\TestLiveComponent, 1: SavedExplorerView, 2: array<string, mixed>}
     */
    protected function createUserViewShellComponent(): array
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $service = self::getContainer()->get(SavedExplorerViewService::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $state = $mapper->toStateArray($viewFactory->createDefault($filter));
        $view = $service->create($user, 'My user view', $state, 'Original description');
        $view->setCreatedBy($user);
        self::getContainer()->get(SavedExplorerViewRepository::class)->save($view);

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $state,
            'locale' => 'en',
            'libraryUrl' => '/statistics/analysis/library',
            'savedViewId' => $view->getId(),
            'savedViewTitle' => 'My user view',
            'savedViewDescription' => 'Original description',
            'canSave' => true,
            'canSaveAs' => true,
        ])->actingAs($user);

        return [$testComponent, $view, $state];
    }
}
