<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
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
    use SeedsExplorerSystemViewsTrait;

    public function testMountWithSavedGroupedBarConfig(): void
    {
        self::bootKernel();
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $result = $loader->load('gender-over-time', $filter, null);

        $user = UserFactory::createOne(['username' => 'explorer-saved-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $result->state,
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();

        self::assertSame('grouped_bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertSame('time', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['columns']['dimension'] ?? null);
    }

    public function testOpenEditShowsPercentCheckboxForGenderOverTime(): void
    {
        self::bootKernel();
        $this->seedExplorerSystemViews();
        $loader = self::getContainer()->get(SavedExplorerViewLoader::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );
        $result = $loader->load('gender-over-time', $filter, null);

        $user = UserFactory::createOne(['username' => 'explorer-percent-'.bin2hex(random_bytes(4))]);
        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $result->state,
            'locale' => 'en',
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-show-percent-field"]')->count(),
        );
    }

    public function testOpenEditKeepsLibraryLinkVisible(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-library-link"]')->count(),
        );
        self::assertSame(
            '/statistics/analysis/library',
            $testComponent->component()->libraryUrl,
        );
    }

    public function testOpenEditShowsMatrixStructurePreview(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-preview"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-row"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-structure-metric"]')->count(),
        );
    }

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

    public function testParticipantSeesSaveAsActionAfterEdit(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'libraryUrl' => '/statistics/analysis/library',
            'canSaveAs' => true,
        ])->actingAs($user);
        $render = $testComponent->render();

        self::assertCount(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-save-as-open"]'),
        );

        $formName = $this->formName($render);
        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, ['chartType' => 'line']))
            ->call('applyEdit')
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-analysis-explorer-save-as-open"]')->count(),
        );
    }

    public function testSaveAndFavoriteDisabledUntilConfigChanges(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'savedViewId' => 42,
            'canSave' => true,
            'canSaveAs' => true,
            'canFavorite' => true,
            'favoriteUrl' => '/favorite',
            'favoriteToken' => 'explorer_favorite_42',
        ])->actingAs($user);

        $render = $testComponent->render();
        self::assertNotNull($render->crawler()->filter('[data-testid="stats-analysis-explorer-save"]')->attr('disabled'));
        self::assertNull($render->crawler()->filter('[data-testid="stats-analysis-explorer-favorite-toggle"]')->attr('disabled'));

        $formName = $this->formName($render);
        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, ['chartType' => 'line']))
            ->call('applyEdit')
            ->render();

        self::assertNull($updatedRender->crawler()->filter('[data-testid="stats-analysis-explorer-save"]')->attr('disabled'));
        self::assertNotNull($updatedRender->crawler()->filter('[data-testid="stats-analysis-explorer-favorite-toggle"]')->attr('disabled'));
    }

    public function testOpenSaveAsPrefillsTitleAndDescriptionFromAppliedConfig(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $descriptionFactory = self::getContainer()->get(\App\Statistics\AnalysisExplorer\Application\ExplorerDescriptionFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'savedViewTitle' => 'Stale saved title',
            'savedViewDescription' => 'Stale saved description from original system view.',
            'canSaveAs' => true,
        ])->actingAs($user);

        $render = $testComponent->render();
        $formName = $this->formName($render);
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartType' => 'bar',
            ]))
            ->call('applyEdit')
            ->call('openSaveAs');

        $config = $testComponent->component()->appliedConfig();
        self::assertNotNull($config);
        self::assertSame($config->title, $testComponent->component()->saveAsTitle);
        self::assertSame($descriptionFactory->descriptionForConfig($config), $testComponent->component()->saveAsDescription);
        self::assertNotSame('Stale saved title', $testComponent->component()->saveAsTitle);
        self::assertNotSame('Stale saved description from original system view.', $testComponent->component()->saveAsDescription);
    }

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
            'rowDimension' => 'time',
            'rowGrain' => 'year',
            'columnDimension' => 'urgency',
            'columnGrain' => 'total',
            'chartType' => 'line',
        ]));

        self::assertSame('bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        self::assertSame('month', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
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

    public function testApplyEditChangesTimeGrainInAppliedState(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'year',
                'columnDimension' => 'gender',
                'columnGrain' => 'total',
            ]))
            ->call('applyEdit');

        self::assertSame('year', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
    }

    public function testApplyEditChangesDimensionToGenderWithTotalGrain(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartType' => 'bar',
            ]))
            ->call('applyEdit');

        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('total', $testComponent->component()->appliedConfigState['query']['rows']['grain'] ?? null);
    }

    public function testApplyEditGenderMonthUsesGroupedBarChart(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $updatedRender = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => 'gender',
                'columnGrain' => 'total',
                'chartType' => 'grouped_bar',
            ]))
            ->call('applyEdit')
            ->render();

        self::assertSame('grouped_bar', $testComponent->component()->appliedConfigState['presentation']['chartType'] ?? null);
        $chart = $updatedRender->crawler()->filter('[data-controller="generic-analysis-chart"]');
        if ($chart->count() > 0) {
            $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
            self::assertNotNull($specsRaw);
            self::assertStringContainsString('"grouped_bar"', $specsRaw);
        }
    }

    public function testDimensionChangeShowsTimeGrainField(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $render = $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => 'urgency',
                'columnGrain' => 'total',
            ]))
            ->call('refreshEditForm')
            ->render();

        self::assertGreaterThan(0, $render->crawler()->filter('[data-testid="stats-analysis-explorer-row-grain-field"]')->count());
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

    public function testEditDrawerAnalysisAndPresentationExpandedByDefault(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $crawler = $render->crawler();

        self::assertStringContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-scope"]')->attr('class') ?? '',
        );
        self::assertStringContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-period"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-analysis"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            'collapsed',
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-presentation"]')->attr('class') ?? '',
        );
        self::assertStringNotContainsString(
            ' show',
            ' '.$crawler->filter('#analysisExplorerEditScopePanel')->attr('class'),
        );
        self::assertStringNotContainsString(
            ' show',
            ' '.$crawler->filter('#analysisExplorerEditPeriodPanel')->attr('class'),
        );
        self::assertStringContainsString(
            'show',
            $crawler->filter('#analysisExplorerEditAnalysisPanel')->attr('class') ?? '',
        );
        self::assertStringContainsString(
            'show',
            $crawler->filter('#analysisExplorerEditPresentationPanel')->attr('class') ?? '',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-analysis"] .analysis-explorer-accordion-state-icon--expanded')->count(),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-scope"] .analysis-explorer-accordion-state-icon--collapsed')->count(),
        );
    }

    public function testViewMetadataSectionHiddenWithoutParticipant(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertCount(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-edit-section-view-metadata"]'),
        );
    }

    public function testViewMetadataSectionVisibleForParticipantWithoutSavedView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'libraryUrl' => '/statistics/analysis/library',
            'canSaveAs' => true,
        ])->actingAs($user);

        $testComponent->render();
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        self::assertGreaterThan(
            0,
            $render->crawler()->filter('[data-testid="stats-analysis-explorer-edit-section-view-metadata"]')->count(),
        );
    }

    public function testViewMetadataFieldsRemainEnabledWhenConfigDirty(): void
    {
        [$testComponent] = $this->createUserViewShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');
        $testComponent->call('setChartRowLimit', ['limit' => '5']);
        $testComponent->call('openEdit');

        $render = $testComponent->render();
        $crawler = $render->crawler();

        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-edit-section-view-metadata"]')->count(),
        );
        self::assertCount(
            0,
            $crawler->filter('[data-testid="stats-analysis-explorer-view-metadata-dirty-hint"]'),
        );
        self::assertNull($crawler->filter('[data-testid="stats-analysis-explorer-edit-view-title"]')->attr('disabled'));
        self::assertNull($crawler->filter('[data-testid="stats-analysis-explorer-edit-view-description"]')->attr('disabled'));
    }

    public function testApplyEditUpdatesMetadataAndEnablesSave(): void
    {
        [$testComponent, $view] = $this->createUserViewShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $testComponent->set('editViewTitle', 'Renamed user view');
        $testComponent->set('editViewDescription', 'Updated description');
        $configBeforeApply = $testComponent->component()->appliedConfigState;
        $testComponent->call('applyEdit');

        $component = $testComponent->component();
        self::assertSame('Renamed user view', $component->savedViewTitle);
        self::assertSame('Updated description', $component->savedViewDescription);
        self::assertSame($configBeforeApply, $component->appliedConfigState);
        self::assertTrue($component->hasUnsavedChanges);
        self::assertTrue($component->metadataManuallyEdited);

        $testComponent->call('save');

        $reloaded = self::getContainer()->get(SavedExplorerViewRepository::class)->find($view->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Renamed user view', $reloaded->getTitle());
        self::assertSame('Updated description', $reloaded->getDescription());
        self::assertFalse($testComponent->component()->hasUnsavedChanges);
    }

    public function testApplyEditMetadataOnlyEnablesSaveWithoutConfigChange(): void
    {
        [$testComponent] = $this->createUserViewShellComponent();
        $testComponent->render();
        self::assertFalse($testComponent->component()->hasUnsavedChanges);

        $testComponent->call('openEdit');
        $configBeforeApply = $testComponent->component()->appliedConfigState;
        $testComponent
            ->set('editViewTitle', 'Metadata-only title')
            ->set('editViewDescription', 'Metadata-only description')
            ->call('applyEdit');

        $component = $testComponent->component();
        self::assertSame($configBeforeApply, $component->appliedConfigState);
        self::assertTrue($component->hasUnsavedChanges);
        self::assertSame('Metadata-only title', $component->savedViewTitle);
    }

    public function testOpenSaveAsUsesManualMetadataWhenEdited(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $viewFactory = self::getContainer()->get(DefaultAnalysisViewFactory::class);
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::All,
        );

        $testComponent = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $mapper->toStateArray($viewFactory->createDefault($filter)),
            'locale' => 'en',
            'canSaveAs' => true,
        ])->actingAs($user);

        $render = $testComponent->render();
        $formName = $this->formName($render);
        $testComponent
            ->call('openEdit')
            ->set('editViewTitle', 'My custom library title')
            ->set('editViewDescription', 'My custom library description')
            ->call('applyEdit')
            ->call('openEdit')
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartType' => 'bar',
            ]))
            ->call('applyEdit')
            ->call('openSaveAs');

        self::assertSame('My custom library title', $testComponent->component()->saveAsTitle);
        self::assertSame('My custom library description', $testComponent->component()->saveAsDescription);
    }

    public function testApplyEditWithChartRowLimitFromPresentationSection(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $testComponent->call('openEdit');

        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartRowLimit' => '5',
            ]))
            ->call('applyEdit');

        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['rows']['dimension'] ?? null);
        self::assertSame('5', $testComponent->component()->appliedConfigState['presentation']['chartRowLimit'] ?? null);
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
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => '',
                'metric' => 'allocation_count',
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
            'libraryUrl' => '/statistics/analysis/library',
        ])->actingAs($user);
    }

    /**
     * @return array{0: \Symfony\UX\LiveComponent\Test\TestLiveComponent, 1: SavedExplorerView, 2: array<string, mixed>}
     */
    private function createUserViewShellComponent(): array
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
