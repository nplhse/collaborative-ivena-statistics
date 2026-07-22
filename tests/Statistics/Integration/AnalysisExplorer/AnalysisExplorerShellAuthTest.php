<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellAuthTest extends AnalysisExplorerShellTestCase
{
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
}
