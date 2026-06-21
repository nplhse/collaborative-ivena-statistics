<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\LiveComponent;

use App\Statistics\AnalysisExplorer\Application\AnalysisRunnerRegistry;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigValidator;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerChartPresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTablePresenter;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
     * Applied analysis configuration (scope, period, grain, chart). Updated on mount and Apply only.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $appliedConfigState = [];

    #[LiveProp]
    public bool $isEditOpen = false;

    #[LiveProp]
    public int $analysisRevision = 0;

    public ?AnalysisRunResult $result = null;

    /** @var array<string, array<string, mixed>> */
    #[LiveProp]
    public array $chartSpecs = [];

    #[LiveProp]
    public string $defaultChartType = 'bar';

    #[LiveProp]
    public bool $hasChart = false;

    public ?ExplorerResultsTableViewModel $table = null;

    #[LiveProp]
    public string $locale = 'en';

    private ?ExplorerEditFormData $editFormData = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly AnalysisRunnerRegistry $runnerRegistry,
        private readonly ExplorerAnalysisQueryFactory $queryFactory,
        private readonly ExplorerChartPresenter $chartPresenter,
        private readonly ExplorerResultsTablePresenter $tablePresenter,
        private readonly ExplorerConfigMapper $configMapper,
        private readonly AnalysisViewConfigValidator $configValidator,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $appliedConfigState
     */
    public function mount(array $appliedConfigState = [], string $locale = 'en'): void
    {
        $this->locale = $locale;

        if ([] !== $appliedConfigState) {
            $this->appliedConfigState = $appliedConfigState;
        }

        $this->rerunAnalysis();
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
        $this->resetForm();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->resetForm();
    }

    #[LiveAction]
    public function refreshEditForm(): void
    {
        if (!$this->isEditOpen) {
            return;
        }

        $this->submitForm(false);

        /** @var ExplorerEditFormData $formData */
        $formData = $this->getForm()->getData();
        $this->editFormData = $formData;
        $this->resetForm();
    }

    #[LiveAction]
    public function applyEdit(): void
    {
        $currentConfig = $this->appliedConfig();
        if (!$currentConfig instanceof AnalysisViewConfig) {
            return;
        }

        $this->normalizeSubmittedFormValues();
        $this->submitForm(true);

        /** @var ExplorerEditFormData $formData */
        $formData = $this->getForm()->getData();
        $newConfig = $this->configMapper->toViewConfig($formData, $currentConfig, $this->resolveUser());
        $this->configValidator->validate($newConfig);

        $this->appliedConfigState = $this->configMapper->toStateArray($newConfig);
        $this->editFormData = null;
        $this->isEditOpen = false;
        $this->resetForm();
        $this->rerunAnalysis();
    }

    private function normalizeSubmittedFormValues(): void
    {
        $formName = $this->getFormName();
        if (!isset($this->formValues[$formName]) || !\is_array($this->formValues[$formName])) {
            return;
        }

        if (!isset($this->formValues[$formName]['scopePeriod']) || !\is_array($this->formValues[$formName]['scopePeriod'])) {
            return;
        }

        $scopeDetail = $this->formValues[$formName]['scopePeriod']['scopeDetail'] ?? null;
        if (\is_int($scopeDetail) || \is_float($scopeDetail)) {
            $this->formValues[$formName]['scopePeriod']['scopeDetail'] = (string) $scopeDetail;
        }
    }

    private function rerunAnalysis(): void
    {
        $currentConfig = $this->appliedConfig();
        if (!$currentConfig instanceof AnalysisViewConfig) {
            return;
        }

        $query = $this->queryFactory->create($currentConfig, $this->resolveUser());
        $this->result = $this->runnerRegistry->run($currentConfig, $query);
        $this->chartSpecs = $this->chartPresenter->buildSpecs($this->result, $currentConfig->presentation);
        $this->defaultChartType = $this->chartPresenter->defaultChartType($currentConfig->presentation);
        $this->hasChart = $this->chartPresenter->hasChart($this->result);
        $this->table = $this->tablePresenter->create($currentConfig, $this->result);
        ++$this->analysisRevision;
    }
}
