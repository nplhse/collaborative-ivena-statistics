<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\LiveComponent;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigValidator;
use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisSummaryViewModel;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolverInterface;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerDescriptionFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditAxisSwapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormFilterFieldMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormSummaryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerFilterBadgePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTablePresenter;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\SavedExplorerViewForbiddenException;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsSourceDataProbe;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[IsGranted('ROLE_USER')]
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
    public bool $isContextOpen = false;

    #[LiveProp]
    public int $analysisRevision = 0;

    #[LiveProp]
    public ?string $configWarning = null;

    #[LiveProp]
    public ?string $saveNotice = null;

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
    public ?string $savedViewAuthorName = null;

    #[LiveProp(writable: false)]
    public ?string $savedViewAuthorUrl = null;

    #[LiveProp(writable: false)]
    public ?string $savedViewActivityKind = null;

    #[LiveProp(writable: false)]
    public ?string $savedViewActivityRelative = null;

    #[LiveProp(writable: false)]
    public ?string $savedViewActivityAbsolute = null;

    #[LiveProp(writable: false)]
    public ?string $savedViewActivityIso = null;

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
    public string $viewVisibility = 'private';

    #[LiveProp(writable: false)]
    public bool $canChangeVisibility = false;

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
    public string $saveAsVisibility = 'private';

    #[LiveProp(writable: true)]
    public bool $isEditMetadataOpen = false;

    #[LiveProp(writable: true)]
    public string $editMetadataTitle = '';

    #[LiveProp(writable: true)]
    public string $editMetadataDescription = '';

    #[LiveProp(writable: true)]
    public string $editMetadataVisibility = 'private';

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

    public ?ExplorerResultsTableViewModel $table = null;

    public ?AnalysisSummaryViewModel $analysisSummary = null;

    public ?string $emptyReason = null;

    public bool $canImport = false;

    private ?ExplorerEditFormData $editFormData = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly AnalysisExecution $analysisExecution,
        private readonly ExplorerChartPresenter $chartPresenter,
        private readonly ExplorerResultsTablePresenter $tablePresenter,
        private readonly ExplorerConfigMapper $configMapper,
        private readonly AnalysisViewConfigValidator $configValidator,
        private readonly AnalysisViewConfigNormalizer $configNormalizer,
        private readonly ExplorerEditFormNormalizer $editFormNormalizer,
        private readonly ExplorerEditFormFilterFieldMapper $editFormFilterFieldMapper,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly SavedExplorerViewService $savedViewService,
        private readonly SavedExplorerViewRepository $savedViewRepository,
        private readonly ExplorerDescriptionFactory $descriptionFactory,
        private readonly ExplorerEditFormSummaryFactory $editFormSummaryFactory,
        private readonly ExplorerEditAxisSwapper $editAxisSwapper,
        private readonly ExplorerAnalysisSummaryFactory $analysisSummaryFactory,
        private readonly ExplorerAnalysisSummaryLabelResolverInterface $summaryLabelResolver,
        private readonly ExplorerFilterBadgePresenter $filterBadgePresenter,
        private readonly HospitalAccessInterface $hospitalAccess,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UsageAnalytics $usageAnalytics,
        private readonly StatisticsSourceDataProbe $sourceDataProbe,
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
        ?string $savedViewAuthorName = null,
        ?string $savedViewAuthorUrl = null,
        ?string $savedViewActivityKind = null,
        ?string $savedViewActivityRelative = null,
        ?string $savedViewActivityAbsolute = null,
        ?string $savedViewActivityIso = null,
        bool $isSystemView = false,
        bool $canSave = false,
        bool $canSaveAs = false,
        bool $canFavorite = false,
        bool $isFavorite = false,
        ?string $favoriteUrl = null,
        ?string $favoriteToken = null,
        string $viewVisibility = 'private',
        bool $canChangeVisibility = false,
    ): void {
        $this->locale = $locale;
        $this->libraryUrl = $libraryUrl;
        $this->savedViewId = $savedViewId;
        $this->savedViewTitle = $savedViewTitle;
        $this->savedViewDescription = $savedViewDescription;
        $this->savedViewAuthorName = $savedViewAuthorName;
        $this->savedViewAuthorUrl = $savedViewAuthorUrl;
        $this->savedViewActivityKind = $savedViewActivityKind;
        $this->savedViewActivityRelative = $savedViewActivityRelative;
        $this->savedViewActivityAbsolute = $savedViewActivityAbsolute;
        $this->savedViewActivityIso = $savedViewActivityIso;
        $this->isSystemView = $isSystemView;
        $this->canSave = $canSave;
        $this->canSaveAs = $canSaveAs;
        $this->canFavorite = $canFavorite;
        $this->isFavorite = $isFavorite;
        $this->favoriteUrl = $favoriteUrl;
        $this->favoriteToken = $favoriteToken;
        $this->viewVisibility = $viewVisibility;
        $this->canChangeVisibility = $canChangeVisibility;

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

        $this->syncClosedEditMetadataDraft();
    }

    #[PreReRender(priority: 10)]
    public function syncClosedEditMetadataDraft(): void
    {
        if ($this->isEditMetadataOpen) {
            return;
        }

        $this->editMetadataTitle = $this->savedViewTitle ?? '';
        $this->editMetadataDescription = $this->savedViewDescription ?? '';
        $this->editMetadataVisibility = $this->viewVisibility;
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
            $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($config);
        }
    }

    #[PreReRender(priority: -100)]
    public function ensureAnalysisResult(): void
    {
        $this->canImport = $this->sourceDataProbe->canImport($this->resolveUser());
        if (!$this->result instanceof AnalysisRunResult) {
            $this->rerunAnalysis();
        }
    }

    #[LiveAction]
    public function clearAnalysisFilters(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig || [] === $config->filters) {
            return;
        }

        $this->appliedConfigState = $this->configMapper->toStateArray($config->withFilters([]));
        $this->editFormData = null;
        $this->result = null;
        $this->resetForm();
        $this->rerunAnalysis();
        $this->syncUnsavedChangeState();
    }

    public function contextLocationLabel(): string
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return '';
        }

        $filter = $config->statisticsFilter;
        $user = $this->resolveUser();
        if (StatisticsFilterScope::Public === $filter->scope
            || (StatisticsFilterScope::MyHospitals === $filter->scope
                && $user instanceof User
                && 0 === $this->hospitalAccess->countAccessibleHospitals($user))
        ) {
            return $this->translator->trans('stats.filter.scope.public', [], 'statistics', $this->locale);
        }

        return $this->summaryLabelResolver->scopeLabel($filter, $user, $this->locale);
    }

    public function contextPeriodLabel(): string
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return '';
        }

        return $this->summaryLabelResolver->periodLabel($config->statisticsFilter, $this->locale);
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    public function activeFilterBadges(): array
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return [];
        }

        return $this->filterBadgePresenter->present($config, $this->locale);
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

    public function canSwapEditAxes(): bool
    {
        if ($this->isEditOpen || $this->isContextOpen) {
            $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        } else {
            $config = $this->appliedConfig();
            if (!$config instanceof AnalysisViewConfig) {
                return false;
            }

            $formData = $this->configMapper->toFormData($config);
        }

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
        $this->populateEditViewMetadataFields($config);
        $this->editViewTitleAtOpen = $this->editViewTitle;
        $this->editViewDescriptionAtOpen = $this->editViewDescription;
        $this->isEditOpen = true;
        $this->isContextOpen = false;
        $this->configWarning = null;
        $this->saveNotice = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function openContext(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $this->editFormData = $this->configMapper->toFormData($config);
        $this->isContextOpen = true;
        $this->isEditOpen = false;
        $this->configWarning = null;
        $this->saveNotice = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function resetContext(): void
    {
        if (!$this->isContextOpen) {
            return;
        }

        $this->submitForm(false);
        $formData = $this->editFormNormalizer->normalize($this->syncFormDataFromForm());
        $user = $this->resolveUser();
        $scopeGroup = 'public';
        if ($user instanceof User
            && !$this->hospitalAccess->isAdminHospitalScopeUser($user)
            && $this->hospitalAccess->canUseMyHospitalsScope($user)
        ) {
            $scopeGroup = 'my_hospitals';
        }

        $formData->scopePeriod = new StatisticsScopePeriodFormData(scopeGroup: $scopeGroup, period: 'all');
        $this->editFormData = $this->editFormNormalizer->normalize($formData);
        $this->resetForm();
        $this->submitForm(false);
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editFormData = null;
        $config = $this->appliedConfig();
        if ($config instanceof AnalysisViewConfig) {
            $this->populateEditViewMetadataFields($config);
        } else {
            $this->editViewTitle = $this->savedViewTitle ?? '';
            $this->editViewDescription = $this->savedViewDescription ?? '';
        }
        $this->isEditOpen = false;
        $this->isContextOpen = false;
        $this->configWarning = null;
        $this->saveNotice = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function refreshEditForm(): void
    {
        if (!$this->isEditOpen && !$this->isContextOpen) {
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
        $newConfig = $this->configMapper->toViewConfig($formData, $currentConfig, $this->resolveUser());
        $normalizedConfig = $this->configNormalizer->normalize($newConfig);
        $this->setNormalizationWarning($newConfig, $normalizedConfig);

        try {
            $this->configValidator->validate($normalizedConfig);
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics');

            return;
        }

        if (!$this->commitMetadataFromApply($normalizedConfig)) {
            return;
        }

        $previousState = $this->appliedConfigState;
        $this->appliedConfigState = $this->configMapper->toStateArray($normalizedConfig);
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->isContextOpen = false;
        if ([] === $this->configNormalizer->diffWarnings($newConfig, $normalizedConfig)) {
            $this->configWarning = null;
        }
        $this->resetForm();
        $this->refreshAfterCommit($previousState);
        $this->syncUnsavedChangeState();
    }

    #[LiveAction]
    public function swapAxes(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $formData = $this->editFormNormalizer->normalize($this->configMapper->toFormData($config));
        if (!$this->editAxisSwapper->canSwap($formData)) {
            return;
        }

        $this->commitFormData($this->editAxisSwapper->swap($formData));
    }

    #[LiveAction]
    public function setChartType(#[LiveArg] string $chartType): void
    {
        $this->patchApplied(static function (ExplorerEditFormData $formData) use ($chartType): void {
            $formData->chartType = $chartType;
        });
    }

    #[LiveAction]
    public function setTableLayout(#[LiveArg] string $tableLayout): void
    {
        $this->patchApplied(static function (ExplorerEditFormData $formData) use ($tableLayout): void {
            $formData->tableLayout = $tableLayout;
        });
    }

    #[LiveAction]
    public function removeAnalysisFilter(#[LiveArg] string $dimension): void
    {
        $this->patchApplied(static function (ExplorerEditFormData $formData) use ($dimension): void {
            match ($dimension) {
                'department' => $formData->filterDepartmentId = null,
                'speciality' => $formData->filterSpecialityId = null,
                'urgency' => $formData->filterUrgency = null,
                'transport_type' => $formData->filterTransportType = null,
                'gender' => $formData->filterGender = null,
                'age_group' => $formData->filterAgeGroup = null,
                'resus' => $formData->filterResus = null,
                'cpr' => $formData->filterCpr = null,
                'ventilation' => $formData->filterVentilation = null,
                'assignment' => $formData->filterAssignmentId = null,
                'indication' => $formData->filterIndicationId = null,
                'secondary_indication' => $formData->filterSecondaryIndicationId = null,
                'indication_group' => $formData->filterIndicationGroupId = null,
                default => null,
            };
        });
    }

    #[LiveAction]
    public function openSaveAs(): void
    {
        if (!$this->showSaveAs) {
            return;
        }

        $config = $this->appliedConfig();
        if ($config instanceof AnalysisViewConfig) {
            if ($this->metadataManuallyEdited) {
                $this->saveAsTitle = $this->savedViewTitle ?? '';
                $this->saveAsDescription = $this->savedViewDescription ?? '';
            } else {
                $this->saveAsTitle = $config->title;
                $this->saveAsDescription = $this->descriptionFactory->descriptionForConfig($config);
            }
        }

        $this->saveAsVisibility = AnalysisViewVisibility::Private->value;
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

        $title = trim($this->saveAsTitle);
        if ('' === $title) {
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save_as.title_required', [], 'statistics');

            return null;
        }

        try {
            $description = '' !== trim($this->saveAsDescription) ? trim($this->saveAsDescription) : null;
            $visibility = AnalysisViewVisibility::tryFrom($this->saveAsVisibility) ?? AnalysisViewVisibility::Private;
            $view = $this->savedViewService->create(
                $user,
                $title,
                $this->appliedConfigState,
                $description,
                null,
                $visibility,
            );
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics');

            return null;
        }

        $viewId = $view->getId();
        if (null === $viewId) {
            return null;
        }

        $this->metadataManuallyEdited = false;

        return new RedirectResponse($this->urlGenerator->generate('app_stats_analysis_explorer_view', [
            'view' => (string) $viewId,
        ]));
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

        $title = $this->savedViewTitle ?? $view->getTitle();
        $description = null !== $this->savedViewDescription && '' !== trim($this->savedViewDescription)
            ? trim($this->savedViewDescription)
            : $view->getDescription();

        try {
            $this->savedViewService->update(
                $view,
                $user,
                $title,
                $this->appliedConfigState,
                $description,
            );
        } catch (SavedExplorerViewForbiddenException) {
            $this->saveNotice = null;
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save.forbidden', [], 'statistics');

            return;
        } catch (InvalidExplorerConfigException $exception) {
            $this->saveNotice = null;
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics');

            return;
        }

        $this->savedViewTitle = $view->getTitle();
        $this->savedViewDescription = $view->getDescription() ?? '';
        $this->baselineConfigState = $this->appliedConfigState;
        $this->baselineViewTitle = $this->savedViewTitle;
        $this->baselineViewDescription = $this->savedViewDescription;
        $this->metadataManuallyEdited = false;
        $this->syncUnsavedChangeState();
        $this->configWarning = null;
        $this->saveNotice = $this->translator->trans('stats.analysis_explorer.saved', [], 'statistics');
    }

    #[LiveAction]
    public function openEditMetadata(): void
    {
        if (!$this->canChangeVisibility) {
            return;
        }

        $this->editMetadataTitle = $this->savedViewTitle ?? '';
        $this->editMetadataDescription = $this->savedViewDescription ?? '';
        $this->editMetadataVisibility = $this->viewVisibility;
        $this->isEditMetadataOpen = true;
    }

    #[LiveAction]
    public function closeEditMetadata(): void
    {
        $this->isEditMetadataOpen = false;
    }

    #[LiveAction]
    public function submitEditMetadata(): void
    {
        if (!$this->canChangeVisibility || null === $this->savedViewId) {
            return;
        }

        $user = $this->requireParticipant();
        $title = trim($this->editMetadataTitle);
        if ('' === $title) {
            $this->saveNotice = null;
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save_as.title_required', [], 'statistics');

            return;
        }

        $view = $this->savedViewRepository->find($this->savedViewId);
        if (!$view instanceof SavedExplorerView) {
            return;
        }

        $description = '' !== trim($this->editMetadataDescription) ? trim($this->editMetadataDescription) : null;
        $visibility = AnalysisViewVisibility::tryFrom($this->editMetadataVisibility) ?? AnalysisViewVisibility::Private;
        $state = [] !== $this->baselineConfigState ? $this->baselineConfigState : $this->appliedConfigState;

        try {
            $this->savedViewService->update(
                $view,
                $user,
                $title,
                $state,
                $description,
                $visibility,
            );
        } catch (SavedExplorerViewForbiddenException) {
            $this->saveNotice = null;
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save.forbidden', [], 'statistics');

            return;
        } catch (InvalidExplorerConfigException $exception) {
            $this->saveNotice = null;
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics');

            return;
        }

        $this->savedViewTitle = $view->getTitle();
        $this->savedViewDescription = $view->getDescription() ?? '';
        $this->baselineViewTitle = $this->savedViewTitle;
        $this->baselineViewDescription = $this->savedViewDescription;
        $this->viewVisibility = $view->getVisibility()->value;
        $this->metadataManuallyEdited = false;
        $this->isEditMetadataOpen = false;
        $this->syncUnsavedChangeState();
        $this->configWarning = null;
        $this->saveNotice = $this->translator->trans('stats.analysis_explorer.saved', [], 'statistics');
    }

    private function syncUnsavedChangeState(): void
    {
        $configDirty = $this->configStatesDiffer(
            $this->baselineConfigState,
            $this->appliedConfigState,
        );
        $metadataDirty = $this->canSave && $this->metadataDiffersFromBaseline();
        $this->hasUnsavedChanges = $configDirty || $metadataDirty;
        $this->showSaveAs = $this->canSaveAs && $configDirty;
        if ($this->hasUnsavedChanges) {
            $this->saveNotice = null;
        }
    }

    private function metadataDiffersFromBaseline(): bool
    {
        return ($this->savedViewTitle ?? '') !== ($this->baselineViewTitle ?? '')
            || ($this->savedViewDescription ?? '') !== ($this->baselineViewDescription ?? '');
    }

    private function initializeMetadataBaselines(): void
    {
        $config = $this->appliedConfig();
        if (null !== $this->savedViewTitle && '' !== trim($this->savedViewTitle)) {
            $this->baselineViewTitle = $this->savedViewTitle;
            $this->baselineViewDescription = $this->savedViewDescription ?? '';

            return;
        }

        if ($config instanceof AnalysisViewConfig) {
            $this->baselineViewTitle = $config->title;
            $this->baselineViewDescription = $this->descriptionFactory->descriptionForConfig($config);
            $this->savedViewTitle = $this->baselineViewTitle;
            $this->savedViewDescription = $this->baselineViewDescription;
        }
    }

    private function populateEditViewMetadataFields(AnalysisViewConfig $config): void
    {
        if ($this->metadataManuallyEdited) {
            $this->editViewTitle = $this->savedViewTitle ?? '';
            $this->editViewDescription = $this->savedViewDescription ?? '';

            return;
        }

        $this->editViewTitle = $this->savedViewTitle ?? $config->title;
        $this->editViewDescription = $this->savedViewDescription ?? $this->descriptionFactory->descriptionForConfig($config);
    }

    private function commitMetadataFromApply(AnalysisViewConfig $normalizedConfig): bool
    {
        if ($this->metadataEditedInCurrentDrawerSession()) {
            $this->metadataManuallyEdited = true;
        }

        if ($this->metadataManuallyEdited) {
            $title = trim($this->editViewTitle);
            if ('' === $title) {
                $this->configWarning = $this->translator->trans('stats.analysis_explorer.save_as.title_required', [], 'statistics');

                return false;
            }

            $this->savedViewTitle = $title;
            $this->savedViewDescription = trim($this->editViewDescription);

            return true;
        }

        $this->savedViewTitle = $normalizedConfig->title;
        $this->savedViewDescription = $this->descriptionFactory->descriptionForConfig($normalizedConfig);

        return true;
    }

    private function metadataEditedInCurrentDrawerSession(): bool
    {
        return trim($this->editViewTitle) !== trim($this->editViewTitleAtOpen)
            || trim($this->editViewDescription) !== trim($this->editViewDescriptionAtOpen);
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

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function configStatesDiffer(array $left, array $right): bool
    {
        return json_encode($left, \JSON_THROW_ON_ERROR) !== json_encode($right, \JSON_THROW_ON_ERROR);
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
        $this->emptyReason = null;

        $currentConfig = $this->appliedConfig();
        if (!$currentConfig instanceof AnalysisViewConfig) {
            $this->emptyReason = 'no_config';
            $this->clearPresentation();

            return;
        }

        $originalConfig = $currentConfig;
        $execution = $this->analysisExecution->execute($currentConfig, $this->resolveUser());
        $currentConfig = $execution->config;
        $this->setNormalizationWarning($originalConfig, $currentConfig);
        if ($execution->configRewritten) {
            $this->appliedConfigState = $this->configMapper->toStateArray($currentConfig);
        }
        if (null !== $execution->warningKey) {
            $this->configWarning = $this->translator->trans($execution->warningKey, $execution->warningParameters, 'statistics');
        }

        $this->emptyReason = $execution->emptyReason;
        $this->result = $execution->result;
        if (null === $execution->warningKey && 'scope_forbidden' !== $execution->emptyReason) {
            $this->usageAnalytics->record(UsageEventName::ANALYSIS_EXPLORER_RUN, FeatureArea::Analysis);
        }

        $this->canImport = $this->sourceDataProbe->canImport($this->resolveUser());

        $this->chartSpecs = $execution->chartSpecs;
        $this->defaultChartType = $execution->defaultChartType;
        $this->hasChart = $execution->hasChart;
        $this->chartRowLimit = $currentConfig->presentation->chartRowLimit->value;
        $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($currentConfig);
        $this->table = $execution->table;
        $this->analysisSummary = $this->analysisSummaryFactory->create(
            $currentConfig,
            $this->resolveUser(),
            $this->locale,
        );
        ++$this->analysisRevision;
    }

    private function patchApplied(\Closure $patch): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig) {
            return;
        }

        $formData = $this->configMapper->toFormData($config);
        $patch($formData);
        $this->commitFormData($formData);
    }

    private function commitFormData(ExplorerEditFormData $formData): void
    {
        $currentConfig = $this->appliedConfig();
        if (!$currentConfig instanceof AnalysisViewConfig) {
            return;
        }

        $formData = $this->editFormNormalizer->normalize($formData);
        $newConfig = $this->configMapper->toViewConfig($formData, $currentConfig, $this->resolveUser());
        $normalizedConfig = $this->configNormalizer->normalize($newConfig);
        $this->setNormalizationWarning($newConfig, $normalizedConfig);

        try {
            $this->configValidator->validate($normalizedConfig);
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics');

            return;
        }

        $previousState = $this->appliedConfigState;
        $this->appliedConfigState = $this->configMapper->toStateArray($normalizedConfig);
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->isContextOpen = false;
        if ([] === $this->configNormalizer->diffWarnings($newConfig, $normalizedConfig)) {
            $this->configWarning = null;
        }
        $this->resetForm();
        $this->refreshAfterCommit($previousState);
        $this->syncUnsavedChangeState();
    }

    /**
     * @param array<string, mixed> $previousState
     */
    private function refreshAfterCommit(array $previousState): void
    {
        if ($this->isPresentationOnlyChange($previousState, $this->appliedConfigState) && $this->result instanceof AnalysisRunResult) {
            $this->refreshPresentation();

            return;
        }

        $this->rerunAnalysis();
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function isPresentationOnlyChange(array $before, array $after): bool
    {
        unset($before['presentation'], $before['title'], $after['presentation'], $after['title']);

        return json_encode($before, \JSON_THROW_ON_ERROR) === json_encode($after, \JSON_THROW_ON_ERROR);
    }

    private function refreshPresentation(): void
    {
        $config = $this->appliedConfig();
        if (!$config instanceof AnalysisViewConfig || !$this->result instanceof AnalysisRunResult) {
            $this->rerunAnalysis();

            return;
        }

        $this->chartSpecs = $this->chartPresenter->buildSpecs($this->result, $config->presentation);
        $this->defaultChartType = $this->chartPresenter->defaultChartType($config->presentation);
        $this->hasChart = $this->chartPresenter->hasChart($this->result);
        $this->chartRowLimit = $config->presentation->chartRowLimit->value;
        $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($config);
        $this->table = $this->tablePresenter->create($config, $this->result);
        $this->analysisSummary = $this->analysisSummaryFactory->create(
            $config,
            $this->resolveUser(),
            $this->locale,
        );
        ++$this->analysisRevision;
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

        $this->chartSpecs = $this->chartPresenter->buildSpecs($this->result, $config->presentation);
        $this->defaultChartType = $this->chartPresenter->defaultChartType($config->presentation);
        $this->hasChart = $this->chartPresenter->hasChart($this->result);
        $this->chartRowLimit = $config->presentation->chartRowLimit->value;
        $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($config);
        $this->analysisSummary = $this->analysisSummaryFactory->create(
            $config,
            $this->resolveUser(),
            $this->locale,
        );
        ++$this->analysisRevision;
    }

    private function shouldShowChartRowLimitControl(AnalysisViewConfig $config): bool
    {
        if ($config->rowAxis->dimensionKey->isTemporalPrimary()) {
            return false;
        }

        if (!$this->result instanceof AnalysisRunResult) {
            return false;
        }

        return $this->distinctRowBucketCount($this->result) > 5;
    }

    private function distinctRowBucketCount(AnalysisRunResult $result): int
    {
        if ($result->hasSeries()) {
            return \count(AnalysisMatrix::fromRunResult($result)->chartLabels());
        }

        $labels = [];
        foreach ($result->rows as $row) {
            $labels[$row->bucketLabel] = true;
        }

        return \count($labels);
    }

    private function setNormalizationWarning(AnalysisViewConfig $original, AnalysisViewConfig $normalized): void
    {
        if ([] === $this->configNormalizer->diffWarnings($original, $normalized)) {
            return;
        }

        $this->configWarning = $this->translator->trans('stats.analysis_explorer.config_normalized', [], 'statistics');
    }

    private function syncFormDataFromForm(): ExplorerEditFormData
    {
        /** @var ExplorerEditFormData $formData */
        $formData = $this->getForm()->getData();
        $formName = $this->getFormName();
        $submitted = isset($this->formValues[$formName]) && \is_array($this->formValues[$formName])
            ? $this->formValues[$formName]
            : [];

        $scopePeriod = $this->resolveScopePeriodFormData($formData->scopePeriod, $submitted);

        $base = new ExplorerEditFormData(
            scopePeriod: $scopePeriod,
            dataSource: \is_string($submitted['dataSource'] ?? null) ? $submitted['dataSource'] : $formData->dataSource,
            rowDimension: \is_string($submitted['rowDimension'] ?? null) ? $submitted['rowDimension'] : $formData->rowDimension,
            rowGrain: \array_key_exists('rowGrain', $submitted)
                ? (\is_string($submitted['rowGrain']) ? $submitted['rowGrain'] : null)
                : $formData->rowGrain,
            columnDimension: \array_key_exists('columnDimension', $submitted)
                ? (\is_string($submitted['columnDimension']) ? $submitted['columnDimension'] : null)
                : $formData->columnDimension,
            columnGrain: \array_key_exists('columnGrain', $submitted)
                ? (\is_string($submitted['columnGrain']) ? $submitted['columnGrain'] : null)
                : $formData->columnGrain,
            metric: \is_string($submitted['metric'] ?? null) ? $submitted['metric'] : $formData->metric,
            showPercentOfTotal: (bool) ($submitted['showPercentOfTotal'] ?? $formData->showPercentOfTotal),
            chartType: \is_string($submitted['chartType'] ?? null) ? $submitted['chartType'] : $formData->chartType,
            tableLayout: \is_string($submitted['tableLayout'] ?? null) ? $submitted['tableLayout'] : $formData->tableLayout,
            chartRowLimit: \is_string($submitted['chartRowLimit'] ?? null) ? $submitted['chartRowLimit'] : $formData->chartRowLimit,
            hospitalPopulation: \is_string($submitted['hospitalPopulation'] ?? null) ? $submitted['hospitalPopulation'] : $formData->hospitalPopulation,
            additionalTableMetrics: \array_key_exists('additionalTableMetrics', $submitted)
                ? array_values(array_filter(
                    \is_array($submitted['additionalTableMetrics']) ? $submitted['additionalTableMetrics'] : [],
                    static fn (mixed $value): bool => \is_string($value) && '' !== $value,
                ))
                : $formData->additionalTableMetrics,
            filterDepartmentId: $formData->filterDepartmentId,
            filterSpecialityId: $formData->filterSpecialityId,
            filterUrgency: $formData->filterUrgency,
            filterTransportType: $formData->filterTransportType,
            filterGender: $formData->filterGender,
            filterAgeGroup: $formData->filterAgeGroup,
            filterResus: $formData->filterResus,
            filterCpr: $formData->filterCpr,
            filterVentilation: $formData->filterVentilation,
            filterAssignmentId: $formData->filterAssignmentId,
            filterIndicationId: $formData->filterIndicationId,
            filterSecondaryIndicationId: $formData->filterSecondaryIndicationId,
            filterIndicationGroupId: $formData->filterIndicationGroupId,
        );

        return $this->editFormFilterFieldMapper->mergeSubmittedFilters($base, $submitted);
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function resolveScopePeriodFormData(
        StatisticsScopePeriodFormData $fallback,
        array $submitted,
    ): StatisticsScopePeriodFormData {
        if (isset($submitted['scopePeriod']) && \is_array($submitted['scopePeriod'])) {
            $scopeSubmitted = $submitted['scopePeriod'];

            return new StatisticsScopePeriodFormData(
                (string) ($scopeSubmitted['scopeGroup'] ?? $fallback->scopeGroup),
                isset($scopeSubmitted['scopeDetail']) ? (string) $scopeSubmitted['scopeDetail'] : $fallback->scopeDetail,
                (string) ($scopeSubmitted['period'] ?? $fallback->period),
                isset($scopeSubmitted['periodYear']) && '' !== $scopeSubmitted['periodYear']
                    ? (int) $scopeSubmitted['periodYear']
                    : $fallback->periodYear,
                isset($scopeSubmitted['periodQuarter']) && '' !== $scopeSubmitted['periodQuarter']
                    ? (int) $scopeSubmitted['periodQuarter']
                    : $fallback->periodQuarter,
                isset($scopeSubmitted['periodMonth']) && '' !== $scopeSubmitted['periodMonth']
                    ? (int) $scopeSubmitted['periodMonth']
                    : $fallback->periodMonth,
            );
        }

        $scopePeriod = $this->getForm()->get('scopePeriod')->getData();
        if ($scopePeriod instanceof StatisticsScopePeriodFormData) {
            return $scopePeriod;
        }

        return $fallback;
    }

    private function clearPresentation(): void
    {
        $this->result = null;
        $this->chartSpecs = [];
        $this->defaultChartType = 'bar';
        $this->hasChart = false;
        $this->showChartRowLimitControl = false;
        $this->chartRowLimit = ExplorerChartRowLimit::All->value;
        $this->table = null;
        $this->analysisSummary = null;
    }
}
