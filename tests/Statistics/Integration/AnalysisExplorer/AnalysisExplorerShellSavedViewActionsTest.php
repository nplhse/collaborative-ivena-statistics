<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Factory\UserFactory;

final class AnalysisExplorerShellSavedViewActionsTest extends AnalysisExplorerShellTestCase
{
    public function testSubmitSaveAsRejectsABlankTitleAndStoresANamedView(): void
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
        $formName = $this->formName($render);
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'gender',
                'rowGrain' => 'total',
                'chartType' => 'bar',
            ]))
            ->call('applyEdit')
            ->call('openSaveAs');
        self::assertTrue($testComponent->component()->isSaveAsOpen);
        $testComponent->call('closeSaveAs');
        self::assertFalse($testComponent->component()->isSaveAsOpen);

        $testComponent->set('saveAsTitle', '   ')->call('submitSaveAs');
        self::assertNotNull($testComponent->component()->configWarning);
        self::assertFalse($testComponent->response()->isRedirect());

        $testComponent
            ->set('saveAsTitle', 'Coverage save as')
            ->set('saveAsDescription', 'Kept')
            ->set('saveAsVisibility', 'public')
            ->call('submitSaveAs');

        self::assertTrue($testComponent->response()->isRedirect());
        $saved = self::getContainer()->get(SavedExplorerViewRepository::class)->findOneBy(['title' => 'Coverage save as']);
        self::assertNotNull($saved);
        self::assertSame('Kept', $saved->getDescription());
        self::assertSame('public', $saved->getVisibility()->value);
        self::assertStringContainsString((string) $saved->getId(), (string) $testComponent->response()->headers->get('Location'));
    }

    public function testSubmitEditMetadataUpdatesVisibilityAndRejectsInvalidSaves(): void
    {
        [$testComponent, $view] = $this->createUserViewShellComponent(true);
        $testComponent->render();
        $testComponent->call('openEditMetadata');
        $testComponent->set('editMetadataTitle', 'Draft while open');
        self::assertSame('Draft while open', $testComponent->component()->editMetadataTitle);

        $testComponent->set('editMetadataTitle', '   ')->call('submitEditMetadata');
        self::assertNotNull($testComponent->component()->configWarning);
        self::assertTrue($testComponent->component()->isEditMetadataOpen);

        $testComponent->call('closeEditMetadata');
        self::assertFalse($testComponent->component()->isEditMetadataOpen);
        $testComponent->set('isEditMetadataOpen', false);
        self::assertSame('My user view', $testComponent->component()->editMetadataTitle);

        $testComponent->call('openEditMetadata');
        $testComponent
            ->set('editMetadataTitle', 'Renamed in dialog')
            ->set('editMetadataDescription', 'New description')
            ->set('editMetadataVisibility', 'public')
            ->call('submitEditMetadata');

        self::assertFalse($testComponent->component()->isEditMetadataOpen);
        self::assertSame('Renamed in dialog', $testComponent->component()->savedViewTitle);
        self::assertSame('public', $testComponent->component()->viewVisibility);
        self::assertNotNull($testComponent->component()->saveNotice);

        $reloaded = self::getContainer()->get(SavedExplorerViewRepository::class)->find($view->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Renamed in dialog', $reloaded->getTitle());
        self::assertSame('New description', $reloaded->getDescription());
        self::assertSame('public', $reloaded->getVisibility()->value);

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $viewer = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
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
        $foreign = $service->create($owner, 'Foreign view', $state, 'Owned elsewhere');

        $forbidden = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $state,
            'locale' => 'en',
            'savedViewId' => $foreign->getId(),
            'savedViewTitle' => 'Foreign view',
            'savedViewDescription' => 'Owned elsewhere',
            'canSave' => true,
            'canChangeVisibility' => true,
        ])->actingAs($viewer);
        $forbidden->render();
        $forbidden->set('editMetadataTitle', 'Hijack')->call('submitEditMetadata');
        self::assertNotNull($forbidden->component()->configWarning);
        self::assertNull($forbidden->component()->saveNotice);

        $forbidden->call('setChartType', ['chartType' => 'line'])->call('save');
        self::assertNotNull($forbidden->component()->configWarning);

        $missing = $this->createLiveComponent('AnalysisExplorerShell', [
            'appliedConfigState' => $state,
            'locale' => 'en',
            'savedViewId' => 999999,
            'savedViewTitle' => 'Missing view',
            'canChangeVisibility' => true,
        ])->actingAs($viewer);
        $missing->render();
        $missing->set('editMetadataTitle', 'Still missing')->call('submitEditMetadata');
        self::assertNull($missing->component()->saveNotice);

        $locked = $this->createShellComponent();
        $locked->call('openEditMetadata');
        self::assertFalse($locked->component()->isEditMetadataOpen);
        $locked->call('submitEditMetadata');
        self::assertNull($locked->component()->saveNotice);
    }

    public function testContextChartAndFilterActionsUpdateTheAppliedView(): void
    {
        $testComponent = $this->createShellComponent();
        $testComponent->render();
        $component = $testComponent->component();

        self::assertNotSame('', $component->contextLocationLabel());
        self::assertNotSame('', $component->contextPeriodLabel());
        self::assertSame([], $component->activeFilterBadges());
        self::assertArrayHasKey('metric', $component->editFormSummary());
        self::assertFalse($component->canSwapEditAxes());

        $testComponent->call('resetContext');
        $testComponent->call('openContext');
        self::assertTrue($testComponent->component()->isContextOpen);
        self::assertArrayHasKey('row', $testComponent->component()->editFormSummary());
        $testComponent->call('resetContext');
        self::assertTrue($testComponent->component()->isContextOpen);

        $testComponent->call('setChartType', ['chartType' => 'line']);
        self::assertSame('line', $testComponent->component()->appliedConfigState['presentation']['chartType']);
        $testComponent->call('setTableLayout', ['tableLayout' => 'flat']);

        $testComponent->call('openEdit');
        $formName = $this->formName($testComponent->render());
        $testComponent
            ->submitForm($this->formPayload($formName, [
                'rowDimension' => 'time',
                'rowGrain' => 'month',
                'columnDimension' => 'gender',
                'columnGrain' => 'total',
                'filterUrgency' => '1',
            ]))
            ->call('applyEdit');

        self::assertNotSame([], $testComponent->component()->activeFilterBadges());
        self::assertTrue($testComponent->component()->canSwapEditAxes());
        $testComponent->call('swapAxes');
        self::assertSame('gender', $testComponent->component()->appliedConfigState['query']['rows']['dimension']);

        $testComponent->call('removeAnalysisFilter', ['dimension' => 'urgency']);
        self::assertSame([], $testComponent->component()->appliedConfigState['query']['filters'] ?? []);

        foreach ([
            'department',
            'speciality',
            'transport_type',
            'gender',
            'age_group',
            'resus',
            'cpr',
            'ventilation',
            'assignment',
            'indication',
            'secondary_indication',
            'indication_group',
            'unknown',
        ] as $dimension) {
            $testComponent->call('removeAnalysisFilter', ['dimension' => $dimension]);
        }

        self::assertSame('gender', $testComponent->component()->appliedConfig()->rowAxis->dimensionKey->value);
    }
}
