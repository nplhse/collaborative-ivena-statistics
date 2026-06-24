<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\LiveComponent;

use App\Statistics\AnalysisExplorer\Application\AnalysisRunnerRegistry;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigValidator;
use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerDescriptionFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditAxisSwapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormSummaryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerFilterBadgePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTablePresenter;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewService;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisTotals;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\SavedExplorerViewForbiddenException;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
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

    public ?ExplorerResultsTableViewModel $table = null;

    public ?string $emptyReason = null;

    private ?ExplorerEditFormData $editFormData = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly AnalysisRunnerRegistry $runnerRegistry,
        private readonly ExplorerAnalysisQueryFactory $queryFactory,
        private readonly ExplorerChartPresenter $chartPresenter,
        private readonly ExplorerResultsTablePresenter $tablePresenter,
        private readonly ExplorerConfigMapper $configMapper,
        private readonly AnalysisViewConfigValidator $configValidator,
        private readonly AnalysisViewConfigNormalizer $configNormalizer,
        private readonly ExplorerEditFormNormalizer $editFormNormalizer,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly SavedExplorerViewService $savedViewService,
        private readonly SavedExplorerViewRepository $savedViewRepository,
        private readonly ExplorerDescriptionFactory $descriptionFactory,
        private readonly ExplorerEditFormSummaryFactory $editFormSummaryFactory,
        private readonly ExplorerEditAxisSwapper $editAxisSwapper,
        private readonly ExplorerFilterBadgePresenter $filterBadgePresenter,
        private readonly UrlGeneratorInterface $urlGenerator,
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
            $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($config);
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
        $this->populateEditViewMetadataFields($config);
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
            $this->populateEditViewMetadataFields($config);
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
        $newConfig = $this->configMapper->toViewConfig($formData, $currentConfig, $this->resolveUser());
        $normalizedConfig = $this->configNormalizer->normalize($newConfig);
        $this->setNormalizationWarning($newConfig, $normalizedConfig);

        try {
            $this->configValidator->validate($normalizedConfig);
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters);

            return;
        }

        if (!$this->commitMetadataFromApply($normalizedConfig)) {
            return;
        }

        $this->appliedConfigState = $this->configMapper->toStateArray($normalizedConfig);
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
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save_as.title_required');

            return null;
        }

        try {
            $description = '' !== trim($this->saveAsDescription) ? trim($this->saveAsDescription) : null;
            $view = $this->savedViewService->create(
                $user,
                $title,
                $this->appliedConfigState,
                $description,
            );
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters);

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
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.save.forbidden');

            return;
        } catch (InvalidExplorerConfigException $exception) {
            $this->configWarning = $this->translator->trans($exception->translationKey, $exception->parameters);

            return;
        }

        $this->savedViewTitle = $view->getTitle();
        $this->savedViewDescription = $view->getDescription() ?? '';
        $this->baselineConfigState = $this->appliedConfigState;
        $this->baselineViewTitle = $this->savedViewTitle;
        $this->baselineViewDescription = $this->savedViewDescription;
        $this->metadataManuallyEdited = false;
        $this->syncUnsavedChangeState();
        $this->configWarning = $this->translator->trans('stats.analysis_explorer.saved');
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
                $this->configWarning = $this->translator->trans('stats.analysis_explorer.save_as.title_required');

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
        $normalizedConfig = $this->configNormalizer->normalize($currentConfig);
        $this->setNormalizationWarning($originalConfig, $normalizedConfig);
        if ([] !== $this->configNormalizer->diffWarnings($originalConfig, $normalizedConfig)) {
            $this->appliedConfigState = $this->configMapper->toStateArray($normalizedConfig);
        }
        $currentConfig = $normalizedConfig;

        try {
            $query = $this->queryFactory->create($currentConfig, $this->resolveUser());
            $this->result = $this->runnerRegistry->run($currentConfig, $query);
        } catch (UnsupportedAnalysisException) {
            $this->configWarning ??= $this->translator->trans('stats.analysis_explorer.unsupported_config');
            $this->emptyReason = 'unsupported';
            $this->result = $this->emptyResult($currentConfig);
        } catch (\Throwable $exception) {
            $this->logger->error('Analysis Explorer query failed.', [
                'exception' => $exception,
            ]);
            $this->configWarning = $this->translator->trans('stats.analysis_explorer.query_failed');
            $this->emptyReason = 'query_error';
            $this->result = $this->emptyResult($currentConfig);
        }

        if ([] === $this->result->rows && null === $this->emptyReason) {
            $this->emptyReason = 'no_data';
        }

        $this->chartSpecs = $this->chartPresenter->buildSpecs($this->result, $currentConfig->presentation);
        $this->defaultChartType = $this->chartPresenter->defaultChartType($currentConfig->presentation);
        $this->hasChart = $this->chartPresenter->hasChart($this->result);
        $this->chartRowLimit = $currentConfig->presentation->chartRowLimit->value;
        $this->showChartRowLimitControl = $this->shouldShowChartRowLimitControl($currentConfig);
        $this->table = $this->tablePresenter->create($currentConfig, $this->result);
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

        $this->configWarning = $this->translator->trans('stats.analysis_explorer.config_normalized');
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

        return new ExplorerEditFormData(
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
        );
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

    private function emptyResult(AnalysisViewConfig $config): AnalysisRunResult
    {
        return new AnalysisRunResult(
            title: $config->title,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            rowAxis: $config->rowAxis,
            columnAxis: $config->columnAxis,
            rows: [],
            totals: new AnalysisTotals(grand: []),
        );
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
    }
}
