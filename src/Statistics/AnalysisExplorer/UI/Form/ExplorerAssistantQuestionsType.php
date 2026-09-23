<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\DataSourceCapabilitiesRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantCatalog;
use App\Statistics\AnalysisExplorer\Application\ExplorerAssistantConfigFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditChoicePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantReadiness;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\ButtonFlowType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<ExplorerAssistantDraft>
 */
final class ExplorerAssistantQuestionsType extends AbstractType
{
    public function __construct(
        private readonly ExplorerAssistantConfigFactory $configFactory,
        private readonly ExplorerEditChoicePresenter $choicePresenter,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private readonly ExplorerConfigPreviewFactory $previewFactory,
        private readonly DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private readonly ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $draft = $options['draft'];
        $goal = $draft instanceof ExplorerAssistantDraft ? $draft->goal : null;
        if (!$draft instanceof ExplorerAssistantDraft || !$goal instanceof ExplorerAssistantGoal) {
            return;
        }

        $locale = $options['locale'];
        if (!\is_string($locale) || '' === $locale) {
            $locale = 'en';
        }

        $capabilities = $this->capabilitiesFor($draft);
        $this->alignDraftWithCapabilities($draft, $capabilities);

        match ($goal) {
            ExplorerAssistantGoal::TimeSeries, ExplorerAssistantGoal::Distribution => null,
            ExplorerAssistantGoal::Toplist => $this->addToplistSuggestions($builder, $draft),
            ExplorerAssistantGoal::Matrix => $this->addMatrixSuggestions($builder, $draft),
        };

        $refresh = ['data-action' => 'change->assistant-context#refresh'];
        $rowAxis = $this->rowAxis($draft);
        $this->addDimensionChoice(
            $builder,
            'row',
            $this->choicePresenter->groupedDimensionChoices($capabilities->dimensions, $draft->dataSource, $locale),
            'stats.analysis_explorer.assistant.rows.label',
            'stats.analysis_explorer.assistant.choose',
            true,
            'stats-analysis-explorer-assistant-row',
            $refresh,
        );
        if ($rowAxis instanceof AnalysisAxisRef && $rowAxis->dimensionKey->isTemporalPrimary()) {
            $this->addGrainChoice($builder, 'grain', $capabilities, $refresh, AnalysisDimensionGrain::Year);
        }

        $columnAxis = null;
        if (ExplorerAssistantGoal::Matrix === $goal) {
            $columnDimensions = $rowAxis instanceof AnalysisAxisRef
                ? $capabilities->columnDimensionsFor($rowAxis)
                : $capabilities->dimensions;
            $this->addDimensionChoice(
                $builder,
                'column',
                $this->choicePresenter->groupedDimensionChoices($columnDimensions, $draft->dataSource, $locale),
                'stats.analysis_explorer.assistant.columns.label',
                'stats.analysis_explorer.assistant.choose',
                true,
                'stats-analysis-explorer-assistant-column',
                $refresh,
            );
            $columnAxis = $this->columnAxis($draft, $rowAxis);
            if ($columnAxis instanceof AnalysisAxisRef
                && $columnAxis->dimensionKey->isTemporalPrimary()
                && !$rowAxis?->dimensionKey->isTemporalPrimary()
            ) {
                $this->addGrainChoice($builder, 'columnGrain', $capabilities, $refresh);
            }
        } else {
            $draft->column = null;
            $draft->columnGrain = null;
        }

        $this->addMetric($builder, $draft, $capabilities, $rowAxis, $columnAxis, $locale);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->restoreOnEmptySubmission(...), 100);
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->onPostSubmit(...));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('draft');
        $resolver->setAllowedTypes('draft', ExplorerAssistantDraft::class);
        $resolver->setDefaults([
            'locale' => 'en',
        ]);
        $resolver->setAllowedTypes('locale', 'string');
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();
        $root = $form->getRoot();
        if (!$root instanceof FormFlowInterface) {
            return;
        }

        $clicked = $root->getClickedButton();
        if ($clicked instanceof ButtonFlowInterface && !$clicked->isNextAction()) {
            return;
        }

        $draft = $form->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            return;
        }

        $this->copySubmittedValues($form, $draft);

        $readiness = $this->configFactory->status($draft->toQuery());
        if (ExplorerAssistantReadiness::Ready === $readiness) {
            return;
        }

        $form->addError(new FormError($this->translator->trans($this->noticeKey($readiness), [], 'statistics')));
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     */
    private function addToplistSuggestions(FormBuilderInterface $form, ExplorerAssistantDraft $draft): void
    {
        $index = 1;
        $suggestions = AnalysisDataSourceKey::Hospitals === $draft->dataSource
            ? ExplorerAssistantCatalog::hospitalToplistSuggestions()
            : ExplorerAssistantCatalog::toplistSuggestions();
        foreach ($suggestions as $dimension) {
            $this->addSuggestion($form, 'suggestion_'.$dimension->value, 'stats.analysis_explorer.dimension.'.$dimension->value, $index, $draft->row === $dimension, function (ExplorerAssistantDraft $data, ButtonFlowInterface $button, FormFlowInterface $flow) use ($dimension): void {
                $data->row = $dimension;
                $data->grain = null;
                $flow->getConfig()->getDataStorage()->save($data);
            });
            ++$index;
        }
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     */
    private function addMatrixSuggestions(FormBuilderInterface $form, ExplorerAssistantDraft $draft): void
    {
        $index = 1;
        $suggestions = AnalysisDataSourceKey::Hospitals === $draft->dataSource
            ? ExplorerAssistantCatalog::hospitalMatrixSuggestions()
            : ExplorerAssistantCatalog::matrixSuggestions();
        foreach ($suggestions as [$row, $column]) {
            $this->addSuggestion(
                $form,
                'suggestion_'.$row->value.'_'.$column->value,
                $this->translator->trans('stats.analysis_explorer.assistant.pair', [
                    'rows' => $this->translator->trans('stats.analysis_explorer.dimension.'.$row->value, [], 'statistics'),
                    'columns' => $this->translator->trans('stats.analysis_explorer.dimension.'.$column->value, [], 'statistics'),
                ], 'statistics'),
                $index,
                $draft->row === $row && $draft->column === $column,
                function (ExplorerAssistantDraft $data, ButtonFlowInterface $button, FormFlowInterface $flow) use ($row, $column): void {
                    $data->row = $row;
                    $data->column = $column;
                    $flow->getConfig()->getDataStorage()->save($data);
                },
                false,
            );
            ++$index;
        }
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     * @param array<string, array<string, string>>         $choices
     * @param array<string, string>                        $attr
     */
    private function addDimensionChoice(
        FormBuilderInterface $form,
        string $name,
        array $choices,
        string $label,
        string $placeholder,
        bool $required,
        string $testId,
        array $attr,
    ): void {
        $form->add($name, PreTranslatedChoiceType::class, [
            'label' => $label,
            'choices' => $choices,
            'placeholder' => $placeholder,
            'required' => $required,
            'attr' => $attr + [
                'id' => 'assistant-'.$name,
                'data-testid' => $testId,
            ],
        ]);
        $form->get($name)->addModelTransformer(new CallbackTransformer(
            static fn (?AnalysisDimensionKey $dimension): string => $dimension instanceof AnalysisDimensionKey ? $dimension->value : '',
            static fn (?string $value): ?AnalysisDimensionKey => AnalysisDimensionKey::tryFrom((string) $value),
        ));
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     * @param array<string, string>                        $attr
     */
    private function addGrainChoice(
        FormBuilderInterface $form,
        string $name,
        DataSourceCapabilities $capabilities,
        array $attr,
        AnalysisDimensionGrain $emptyGrain = AnalysisDimensionGrain::Month,
    ): void {
        $choices = [];
        foreach ($capabilities->timeGrains as $grain) {
            $choices[$this->translator->trans('stats.analysis_explorer.dimension.'.$grain->value, [], 'statistics')] = $grain->value;
        }

        $form->add($name, PreTranslatedChoiceType::class, [
            'label' => 'grain' === $name
                ? 'stats.analysis_explorer.assistant.grain.label'
                : 'stats.analysis_explorer.edit.column_grain',
            'choices' => $choices,
            'choice_translation_domain' => false,
            'attr' => $attr + [
                'id' => 'assistant-'.$name,
                'data-testid' => 'grain' === $name
                    ? 'stats-analysis-explorer-assistant-grain'
                    : 'stats-analysis-explorer-assistant-column-grain',
            ],
        ]);
        $form->get($name)->addModelTransformer(new CallbackTransformer(
            fn (?AnalysisDimensionGrain $grain): string => ($grain ?? $emptyGrain)->value,
            fn (?string $value): AnalysisDimensionGrain => AnalysisDimensionGrain::tryFrom((string) $value) ?? $emptyGrain,
        ));
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft> $form
     */
    private function addMetric(
        FormBuilderInterface $form,
        ExplorerAssistantDraft $draft,
        DataSourceCapabilities $capabilities,
        ?AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        string $locale,
    ): void {
        $metrics = $this->compatibleMetrics($draft, $capabilities, $rowAxis, $columnAxis);
        if (!\in_array($draft->metric, $metrics, true)) {
            $draft->metric = $metrics[0] ?? AnalysisMetricKey::defaultFor($draft->dataSource);
        }

        $form->add('metric', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.assistant.metric.label',
            'choices' => $this->choicePresenter->groupedMetricChoices($metrics, $draft->dataSource, $locale),
            'attr' => [
                'id' => 'assistant-metric',
                'data-testid' => 'stats-analysis-explorer-assistant-metric',
            ],
        ]);
        $form->get('metric')->addModelTransformer(new CallbackTransformer(
            static fn (?AnalysisMetricKey $metric): string => ($metric ?? AnalysisMetricKey::defaultFor($draft->dataSource))->value,
            static fn (?string $value): AnalysisMetricKey => AnalysisMetricKey::tryFrom((string) $value) ?? AnalysisMetricKey::defaultFor($draft->dataSource),
        ));
    }

    /**
     * @param FormBuilderInterface<ExplorerAssistantDraft>                                   $form
     * @param callable(ExplorerAssistantDraft, ButtonFlowInterface, FormFlowInterface): void $handler
     */
    private function addSuggestion(
        FormBuilderInterface $form,
        string $name,
        string $label,
        int $index,
        bool $active,
        callable $handler,
        bool $translateLabel = true,
    ): void {
        $options = [
            'label' => $label,
            'validation_groups' => false,
            'handler' => $handler,
            'attr' => [
                'class' => 'btn btn-sm '.($active ? 'btn-primary' : 'btn-outline-primary'),
                'data-testid' => 'stats-analysis-explorer-assistant-suggestion-'.$index,
            ],
        ];
        if (!$translateLabel) {
            $options['translation_domain'] = false;
        }

        $form->add($name, ButtonFlowType::class, $options);
    }

    private function capabilitiesFor(ExplorerAssistantDraft $draft): DataSourceCapabilities
    {
        $user = $this->security->getUser();

        return $this->capabilitiesRegistry->capabilitiesFor(
            $draft->dataSource,
            $user instanceof User ? $user : null,
            $this->statisticsFilterFactory->createFromInput(
                $this->filterInputFactory->fromSideFormData($draft->scopePeriod),
                $user instanceof User ? $user : null,
            ),
        );
    }

    private function alignDraftWithCapabilities(ExplorerAssistantDraft $draft, DataSourceCapabilities $capabilities): void
    {
        if ($draft->row instanceof AnalysisDimensionKey && !\in_array($draft->row, $capabilities->dimensions, true)) {
            $draft->row = null;
            $draft->grain = null;
        }
        if ($draft->column instanceof AnalysisDimensionKey && !\in_array($draft->column, $capabilities->dimensions, true)) {
            $draft->column = null;
            $draft->columnGrain = null;
        }
    }

    private function rowAxis(ExplorerAssistantDraft $draft): ?AnalysisAxisRef
    {
        if (!$draft->row instanceof AnalysisDimensionKey) {
            return null;
        }

        if ($draft->row->isTemporalPrimary()) {
            return AnalysisAxisRef::time($draft->grain ?? AnalysisDimensionGrain::Year);
        }

        return AnalysisAxisRef::breakdown($draft->row);
    }

    private function columnAxis(ExplorerAssistantDraft $draft, ?AnalysisAxisRef $rowAxis): ?AnalysisAxisRef
    {
        if (!$draft->column instanceof AnalysisDimensionKey || $draft->column === $draft->row) {
            return null;
        }

        if ($draft->column->isTemporalPrimary()) {
            if ($rowAxis instanceof AnalysisAxisRef && $rowAxis->dimensionKey->isTemporalPrimary()) {
                return AnalysisAxisRef::time($rowAxis->resolvedGrain());
            }

            return AnalysisAxisRef::time($draft->columnGrain ?? AnalysisDimensionGrain::Month);
        }

        return AnalysisAxisRef::breakdown($draft->column);
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    private function compatibleMetrics(
        ExplorerAssistantDraft $draft,
        DataSourceCapabilities $capabilities,
        ?AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
    ): array {
        if (!$rowAxis instanceof AnalysisAxisRef) {
            return [AnalysisMetricKey::defaultFor($draft->dataSource)];
        }

        $metric = \in_array($draft->metric, $capabilities->primaryMetrics, true)
            ? $draft->metric
            : AnalysisMetricKey::defaultFor($draft->dataSource);
        $formData = new ExplorerEditFormData();
        $formData->dataSource = $draft->dataSource->value;
        $formData->metric = $metric->value;
        $preview = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);
        $metrics = array_values(array_filter(
            $this->metricCapabilityPolicy->metricsForConfig($preview),
            static fn (AnalysisMetricKey $key): bool => AnalysisMetricKey::PercentOfTotal !== $key && $key->isChartable(),
        ));

        return [] === $metrics ? [AnalysisMetricKey::defaultFor($draft->dataSource)] : $metrics;
    }

    /**
     * @param FormInterface<ExplorerAssistantDraft> $form
     */
    private function copySubmittedValues(FormInterface $form, ExplorerAssistantDraft $draft): void
    {
        if ($form->has('row')) {
            $row = $form->get('row')->getData();
            $draft->row = $row instanceof AnalysisDimensionKey ? $row : null;
        }

        if ($form->has('column')) {
            $column = $form->get('column')->getData();
            $draft->column = $column instanceof AnalysisDimensionKey ? $column : null;
        } elseif (ExplorerAssistantGoal::Matrix !== $draft->goal) {
            $draft->column = null;
        }

        if ($form->has('grain')) {
            $grain = $form->get('grain')->getData();
            $draft->grain = $grain instanceof AnalysisDimensionGrain ? $grain : null;
        } elseif ($draft->row?->isTemporalPrimary()) {
            $draft->grain ??= AnalysisDimensionGrain::Year;
        } else {
            $draft->grain = null;
        }

        if ($form->has('columnGrain')) {
            $columnGrain = $form->get('columnGrain')->getData();
            $draft->columnGrain = $columnGrain instanceof AnalysisDimensionGrain ? $columnGrain : null;
        } else {
            $draft->columnGrain = null;
        }

        if ($form->has('metric')) {
            $metric = $form->get('metric')->getData();
            if ($metric instanceof AnalysisMetricKey) {
                $draft->metric = $metric;
            }
        }
    }

    /**
     * Back clears the current step. Keep the structure already stored on the draft.
     */
    public function restoreOnEmptySubmission(FormEvent $event): void
    {
        $submitted = $event->getData();
        if (\is_array($submitted) && [] !== $submitted) {
            return;
        }

        $draft = $event->getForm()->getData();
        if (!$draft instanceof ExplorerAssistantDraft) {
            return;
        }

        $restored = [];
        if ($draft->row instanceof AnalysisDimensionKey) {
            $restored['row'] = $draft->row->value;
        }
        if ($draft->column instanceof AnalysisDimensionKey) {
            $restored['column'] = $draft->column->value;
        }
        if ($draft->grain instanceof AnalysisDimensionGrain) {
            $restored['grain'] = $draft->grain->value;
        }
        if ($draft->columnGrain instanceof AnalysisDimensionGrain) {
            $restored['columnGrain'] = $draft->columnGrain->value;
        }
        $restored['metric'] = $draft->metric->value;

        $event->setData($restored);
    }

    private function noticeKey(ExplorerAssistantReadiness $readiness): string
    {
        return match ($readiness) {
            ExplorerAssistantReadiness::SameAxis => 'stats.analysis_explorer.assistant.same_axis',
            ExplorerAssistantReadiness::Unsupported => 'stats.analysis_explorer.assistant.invalid',
            ExplorerAssistantReadiness::Incomplete, ExplorerAssistantReadiness::Ready => 'stats.analysis_explorer.assistant.incomplete',
        };
    }
}
