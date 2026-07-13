<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\LiveComponent;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisPresentationCoordinator;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisRunner;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditApplyHandler;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditAxisSwapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormSubmittedDataFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormSummaryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerFilterBadgePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerSavedViewHandler;
use App\Statistics\AnalysisExplorer\Application\ExplorerViewStateHandler;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'AnalysisExplorerShell',
    template: '@Statistics/analysis_explorer/AnalysisExplorerShell.html.twig',
)]
final class AnalysisExplorerShell
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    /**
     * Applied analysis configuration. Updated on mount and Apply only.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $appliedConfigState = [];

    #[LiveProp]
    public bool $isEditOpen = false;

    #[LiveProp]
    public int $analysisRevision = 0;

    #[LiveProp]
    public ?string $configWarning = null;

    #[LiveProp]
    public string $locale = 'en';

    #[LiveProp(writable: false)]
    public string $libraryUrl = '';

    #[LiveProp(writable: false)]
    public ?int $savedViewId = null;

    #[LiveProp(writable: true)]
    public ?string $savedViewTitle = null;

    #[LiveProp(writable: true)]
    public ?string $savedViewDescription = null;

    #[LiveProp(writable: false)]
    public bool $isSystemView = false;

    #[LiveProp(writable: false)]
    public bool $canSave = false;

    #[LiveProp(writable: false)]
    public bool $canSaveAs = false;

    /**
     * Baseline config at load or last save; used to detect editor-applied changes.
     *
     * @var array<string, mixed>
     */
    #[LiveProp(writable: false)]
    public array $baselineConfigState = [];

    #[LiveProp]
    public bool $showSaveAs = false;

    #[LiveProp]
    public bool $hasUnsavedChanges = false;

    #[LiveProp(writable: false)]
    public bool $canFavorite = false;

    #[LiveProp(writable: false)]
    public bool $isFavorite = false;

    #[LiveProp(writable: false)]
    public ?string $favoriteUrl = null;

    #[LiveProp(writable: false)]
    public ?string $favoriteToken = null;

    #[LiveProp(writable: false)]
    public string $exportCsvUrl = '';

    #[LiveProp(writable: true)]
    public bool $isSaveAsOpen = false;

    #[LiveProp(writable: true)]
    public string $saveAsTitle = '';

    #[LiveProp(writable: true)]
    public string $saveAsDescription = '';

    #[LiveProp(writable: true)]
    public string $editViewTitle = '';

    #[LiveProp(writable: true)]
    public string $editViewDescription = '';

    #[LiveProp(writable: false)]
    public ?string $baselineViewTitle = null;

    #[LiveProp(writable: false)]
    public ?string $baselineViewDescription = null;

    #[LiveProp(writable: false)]
    public bool $metadataManuallyEdited = false;

    #[LiveProp(writable: false)]
    public string $editViewTitleAtOpen = '';

    #[LiveProp(writable: false)]
    public string $editViewDescriptionAtOpen = '';

    public ?AnalysisRunResult $result = null;

    /** @var array<string, array<string, mixed>> */
    public array $chartSpecs = [];

    public string $defaultChartType = 'bar';

    public bool $hasChart = false;

    #[LiveProp]
    public bool $showChartRowLimitControl = false;

    #[LiveProp]
    public string $chartRowLimit = ExplorerChartRowLimit::All->value;

    public ?\App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel $table = null;

    public ?string $emptyReason = null;

    private ?ExplorerEditFormData $editFormData = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly ExplorerAnalysisRunner $analysisRunner,
        private readonly ExplorerAnalysisPresentationCoordinator $presentationCoordinator,
        private readonly ExplorerConfigMapper $configMapper,
        private readonly ExplorerEditFormNormalizer $editFormNormalizer,
        private readonly ExplorerEditFormSubmittedDataFactory $submittedDataFactory,
        private readonly ExplorerEditApplyHandler $editApplyHandler,
        private readonly ExplorerViewStateHandler $viewStateHandler,
        private readonly ExplorerSavedViewHandler $savedViewHandler,
        private readonly Security $security,
        private readonly SavedExplorerViewRepository $savedViewRepository,
        private readonly ExplorerEditFormSummaryFactory $editFormSummaryFactory,
        private readonly ExplorerEditAxisSwapper $editAxisSwapper,
        private readonly ExplorerFilterBadgePresenter $filterBadgePresenter,
    ) {
    }

    /**
     * @param array<string, mixed> $appliedConfigState
     */
    public function mount(
        array $appliedConfigState = [],
        string $locale = 'en',
        ?string $initialConfigWarning = null,
        string $libraryUrl = '',
        ?int $savedViewId = null,
        ?string $savedViewTitle = null,
        ?string $savedViewDescription = null,
        bool $isSystemView = false,
        bool $canSave = false,
        bool $canSaveAs = false,
        bool $canFavorite = false,
        bool $isFavorite = false,
        ?string $favoriteUrl = null,
        ?string $favoriteToken = null,
    ): void {
        $this->locale = $locale;
        $this->libraryUrl = $libraryUrl;
        $this->savedViewId = $savedViewId;
        $this->savedViewTitle = $savedViewTitle;
        $this->savedViewDescription = $savedViewDescription;
        $this->isSystemView = $isSystemView;
        $this->canSave = $canSave;
        $this->canSaveAs = $canSaveAs;
        $this->canFavorite = $canFavorite;
        $this->isFavorite = $isFavorite;
        $this->favoriteUrl = $favoriteUrl;
        $this->favoriteToken = $favoriteToken;

        if ([] !== $appliedConfigState) {
            $this->appliedConfigState = $appliedConfigState;
        }

        $this->rerunAnalysis();
        $this->baselineConfigState = $this->appliedConfigState;
        $this->initializeMetadataBaselines();
        $this->syncUnsavedChangeState();

        if (null !== $initialConfigWarning) {
            $this->configWarning = $initialConfigWarning;
        }
    }

    #[PreReRender(priority: -50)]
    public function syncChartPresentationState(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $this->chartRowLimit = $config->presentation->chartRowLimit->value;
        if ($this->result instanceof AnalysisRunResult) {
            $this->showChartRowLimitControl = $this->presentationCoordinator->shouldShowChartRowLimitControl($config, $this->result);
        }
    }

    #[PreReRender(priority: -100)]
    public function ensureAnalysisResult(): void
    {
        if (!$this->result instanceof AnalysisRunResult) {
            $this->rerunAnalysis();
        }
    }

    private function resolveUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function appliedConfig(): ?AnalysisViewConfig
    {
        if ([] === $this->appliedConfigState) {
            return null;
        }

        return $this->configMapper->viewConfigFromState($this->appliedConfigState, $this->resolveUser());
    }

    /**
     * @return array{row: string, column: string, metric: string}
     */
    public function editFormSummary(): array
    {
        if ($this->isEditOpen) {
            $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        } elseif ($this->editFormData instanceof ExplorerEditFormData) {
            $formData = $this->editFormData;
        } else {
            $config = $this->appliedConfig();
            $formData = $config instanceof AnalysisViewConfig
                ? $this->configMapper->toFormData($config)
                : new ExplorerEditFormData();
        }

        return $this->editFormSummaryFactory->summarize($formData, $this->resolveUser());
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function activeFilterBadges(): array
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return [];
        }

        return $this->filterBadgePresenter->present($config);
    }

    public function canSwapEditAxes(): bool
    {
        if (!$this->isEditOpen) {
            return false;
        }

        $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());

        return $this->editAxisSwapper->canSwap($formData);
    }

    public function showViewMetadataSection(): bool
    {
        return $this->canSaveAs;
    }

    /**
     * @return FormInterface<ExplorerEditFormData>
     */
    #[\Override]
    protected function instantiateForm(): FormInterface
    {
        if ($this->editFormData instanceof ExplorerEditFormData) {
            $data = $this->editFormData;
        } else {
            $config = $this->appliedConfig();
            $data = $config instanceof AnalysisViewConfig
                ? $this->configMapper->toFormData($config)
                : new ExplorerEditFormData();
        }

        return $this->formFactory->create(ExplorerEditFormType::class, clone $data, [
            'locale' => $this->locale,
        ]);
    }

    #[LiveAction]
    public function openEdit(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $this->editFormData = $this->configMapper->toFormData($config);
        $this->applyEditViewMetadataFields($config);
        $this->editViewTitleAtOpen = $this->editViewTitle;
        $this->editViewDescriptionAtOpen = $this->editViewDescription;
        $this->isEditOpen = true;
        $this->configWarning = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editFormData = null;
        $config = $this->appliedConfig();
        if ($config instanceof AnalysisViewConfig) {
            $this->applyEditViewMetadataFields($config);
        } else {
            $this->editViewTitle = $this->savedViewTitle ?? '';
            $this->editViewDescription = $this->savedViewDescription ?? '';
        }
        $this->isEditOpen = false;
        $this->configWarning = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function refreshEditForm(): void
    {
        if (!$this->isEditOpen) {
            return;
        }

        $this->submitForm(false);

        $this->editFormData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        $this->resetForm();
        $this->submitForm(false);
    }

    #[LiveAction]
    public function swapEditAxes(): void
    {
        if (!$this->isEditOpen || !$this->canSwapEditAxes()) {
            return;
        }

        $this->submitForm(false);

        $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        $this->editFormData = $this->editAxisSwapper->swap($formData);
        $this->resetForm();
        $this->submitForm(false);
    }

    #[LiveAction]
    public function applyEdit(): void
    {
        $currentConfig = $this->appliedConfig();
        if (!$currentConfig instanceof AnalysisViewConfig) {
            return;
        }

        $this->submitForm(false);

        $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        $this->editFormData = $formData;
        $this->resetForm();
        $this->submitForm(true);

        $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        $outcome = $this->editApplyHandler->apply($currentConfig, $formData, $this->resolveUser());
        if (null !== $outcome->configWarning) {
            $this->configWarning = $outcome->configWarning;
        }
        if (!$outcome->applied || !$outcome->normalizedConfig instanceof AnalysisViewConfig) {
            return;
        }

        $metadataOutcome = $this->viewStateHandler->commitMetadataFromApply(
            $outcome->normalizedConfig,
            $this->metadataManuallyEdited,
            $this->viewStateHandler->metadataEditedInCurrentDrawerSession(
                $this->editViewTitle,
                $this->editViewDescription,
                $this->editViewTitleAtOpen,
                $this->editViewDescriptionAtOpen,
            ),
            $this->editViewTitle,
            $this->editViewDescription,
        );
        if (!$metadataOutcome->success) {
            $this->configWarning = $metadataOutcome->configWarning;

            return;
        }

        $this->savedViewTitle = $metadataOutcome->savedViewTitle;
        $this->savedViewDescription = $metadataOutcome->savedViewDescription;
        $this->metadataManuallyEdited = $metadataOutcome->metadataManuallyEdited;

        $this->appliedConfigState = $this->configMapper->toStateArray($outcome->normalizedConfig);
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->configWarning = null;
        $this->resetForm();
        $this->rerunAnalysis();
        $this->syncUnsavedChangeState();
    }

    #[LiveAction]
    public function openSaveAs(): void
    {
        if (!$this->showSaveAs) {
            return;
        }

        $defaults = $this->savedViewHandler->prepareSaveAsDefaults(
            $this->appliedConfig(),
            $this->metadataManuallyEdited,
            $this->savedViewTitle,
            $this->savedViewDescription,
        );
        $this->saveAsTitle = $defaults['title'];
        $this->saveAsDescription = $defaults['description'];
        $this->isSaveAsOpen = true;
    }

    #[LiveAction]
    public function closeSaveAs(): void
    {
        $this->isSaveAsOpen = false;
    }

    #[LiveAction]
    public function submitSaveAs(): ?RedirectResponse
    {
        $user = $this->requireParticipant();
        $outcome = $this->savedViewHandler->saveAs(
            $user,
            $this->saveAsTitle,
            $this->saveAsDescription,
            $this->appliedConfigState,
        );

        if (null !== $outcome->configWarning) {
            $this->configWarning = $outcome->configWarning;

            return null;
        }

        if ($outcome->metadataManuallyEditedReset) {
            $this->metadataManuallyEdited = false;
        }

        return $outcome->redirect;
    }

    #[LiveAction]
    public function save(): void
    {
        if (!$this->canSave || !$this->hasUnsavedChanges || null === $this->savedViewId) {
            return;
        }

        $user = $this->requireParticipant();
        $view = $this->savedViewRepository->find($this->savedViewId);
        if (!$view instanceof SavedExplorerView) {
            return;
        }

        $outcome = $this->savedViewHandler->save(
            $view,
            $user,
            $this->savedViewTitle,
            $this->savedViewDescription,
            $this->appliedConfigState,
        );

        if (!$outcome->saved) {
            $this->configWarning = $outcome->configWarning;

            return;
        }

        $this->savedViewTitle = $outcome->savedViewTitle;
        $this->savedViewDescription = $outcome->savedViewDescription;
        $this->baselineConfigState = $this->appliedConfigState;
        $this->baselineViewTitle = $this->savedViewTitle;
        $this->baselineViewDescription = $this->savedViewDescription;
        $this->metadataManuallyEdited = false;
        $this->syncUnsavedChangeState();
        $this->configWarning = $outcome->configWarning;
    }

    private function syncUnsavedChangeState(): void
    {
        $state = $this->viewStateHandler->unsavedChangeState(
            $this->baselineConfigState,
            $this->appliedConfigState,
            $this->canSave,
            $this->canSaveAs,
            $this->savedViewTitle,
            $this->baselineViewTitle,
            $this->savedViewDescription,
            $this->baselineViewDescription,
        );
        $this->hasUnsavedChanges = $state->hasUnsavedChanges;
        $this->showSaveAs = $state->showSaveAs;
    }

    private function initializeMetadataBaselines(): void
    {
        $baselines = $this->viewStateHandler->initializeMetadataBaselines(
            $this->appliedConfig(),
            $this->savedViewTitle,
            $this->savedViewDescription,
        );
        $this->baselineViewTitle = $baselines['title'];
        $this->baselineViewDescription = $baselines['description'];
        if (null !== $baselines['title']) {
            $this->savedViewTitle = $baselines['title'];
            $this->savedViewDescription = $baselines['description'];
        }
    }

    private function applyEditViewMetadataFields(AnalysisViewConfig $config): void
    {
        $fields = $this->viewStateHandler->populateEditViewMetadataFields(
            $config,
            $this->metadataManuallyEdited,
            $this->savedViewTitle,
            $this->savedViewDescription,
        );
        $this->editViewTitle = $fields['title'];
        $this->editViewDescription = $fields['description'];
    }

    #[LiveAction]
    public function setChartRowLimit(#[LiveArg] string $limit): void
    {
        $this->appliedConfigState = $this->configMapper->mergeChartRowLimitIntoState(
            $this->appliedConfigState,
            ExplorerChartRowLimit::fromValue($limit),
        );
        $this->rebuildCharts();
        $this->syncUnsavedChangeState();
    }

    private function requireParticipant(): User
    {
        $user = $this->resolveUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_PARTICIPANT')) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    private function rerunAnalysis(): void
    {
        $outcome = $this->analysisRunner->run(
            $this->appliedConfigState,
            $this->resolveUser(),
            $this->configWarning,
        );

        if (null !== $outcome->normalizedConfigState) {
            $this->appliedConfigState = $outcome->normalizedConfigState;
        }

        $this->configWarning = $outcome->configWarning;

        $presentation = $this->presentationCoordinator->present(
            $outcome->result,
            $this->appliedConfig(),
            $outcome->emptyReason,
        );
        $this->applyPresentationState($presentation);
    }

    private function rebuildCharts(): void
    {
        if (!$this->result instanceof AnalysisRunResult) {
            return;
        }

        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $presentation = $this->presentationCoordinator->rebuildCharts($this->result, $config);
        $this->chartSpecs = $presentation->chartSpecs;
        $this->defaultChartType = $presentation->defaultChartType;
        $this->hasChart = $presentation->hasChart;
        $this->chartRowLimit = $presentation->chartRowLimit;
        $this->showChartRowLimitControl = $presentation->showChartRowLimitControl;
        $this->analysisRevision += $presentation->analysisRevisionDelta;
    }

    private function syncFormDataFromForm(): ExplorerEditFormData
    {
        /** @var ExplorerEditFormData $formData */
        $formData = $this->getForm()->getData();
        $formName = $this->getFormName();
        /** @var array<string, mixed> $submitted */
        $submitted = isset($this->formValues[$formName]) && \is_array($this->formValues[$formName])
            ? $this->formValues[$formName]
            : [];

        return $this->submittedDataFactory->createFromSubmitted($formData, $submitted, $this->getForm());
    }

    private function applyPresentationState(\App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisPresentationState $state): void
    {
        $this->result = $state->result;
        $this->chartSpecs = $state->chartSpecs;
        $this->defaultChartType = $state->defaultChartType;
        $this->hasChart = $state->hasChart;
        $this->showChartRowLimitControl = $state->showChartRowLimitControl;
        $this->chartRowLimit = $state->chartRowLimit;
        $this->emptyReason = $state->emptyReason;
        if ($state->table instanceof \App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel) {
            $this->table = $state->table;
        } elseif (!$state->result instanceof AnalysisRunResult) {
            $this->table = null;
        }
        $this->analysisRevision += $state->analysisRevisionDelta;
    }
}
