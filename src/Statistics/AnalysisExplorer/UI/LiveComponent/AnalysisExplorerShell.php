<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\LiveComponent;

use App\Statistics\AnalysisExplorer\Application\AnalysisRunnerRegistry;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigValidator;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTablePresenter;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\UnsupportedAnalysisException;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
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

    public ?AnalysisRunResult $result = null;

    /** @var array<string, array<string, mixed>> */
    public array $chartSpecs = [];

    public string $defaultChartType = 'bar';

    public bool $hasChart = false;

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
    ): void {
        $this->locale = $locale;
        $this->libraryUrl = $libraryUrl;

        if ([] !== $appliedConfigState) {
            $this->appliedConfigState = $appliedConfigState;
        }

        $this->rerunAnalysis();

        if (null !== $initialConfigWarning) {
            $this->configWarning = $initialConfigWarning;
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
        $this->isEditOpen = true;
        $this->configWarning = null;
        $this->resetForm();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editFormData = null;
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

        $this->appliedConfigState = $this->configMapper->toStateArray($normalizedConfig);
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->configWarning = null;
        $this->resetForm();
        $this->rerunAnalysis();
    }

    private function rerunAnalysis(): void
    {
        $this->emptyReason = null;
        $this->configWarning = null;

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
        $this->table = $this->tablePresenter->create($currentConfig, $this->result);
        ++$this->analysisRevision;
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
            dimension: \is_string($submitted['dimension'] ?? null) ? $submitted['dimension'] : $formData->dimension,
            metric: \is_string($submitted['metric'] ?? null) ? $submitted['metric'] : $formData->metric,
            timeGrain: \array_key_exists('timeGrain', $submitted)
                ? (\is_string($submitted['timeGrain']) ? $submitted['timeGrain'] : null)
                : $formData->timeGrain,
            chartType: \is_string($submitted['chartType'] ?? null) ? $submitted['chartType'] : $formData->chartType,
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
            metricKey: $config->metricKey,
            dimensionKey: $config->dimensionKey,
            timeGrain: $config->timeGrain,
            rows: [],
            total: 0,
        );
    }

    private function clearPresentation(): void
    {
        $this->result = null;
        $this->chartSpecs = [];
        $this->defaultChartType = 'bar';
        $this->hasChart = false;
        $this->table = null;
    }
}
