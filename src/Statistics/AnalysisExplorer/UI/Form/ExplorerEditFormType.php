<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\AnalysisAxisResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerColumnGrainResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\StatisticsScopePeriodType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<ExplorerEditFormData>
 */
final class ExplorerEditFormType extends AbstractType
{
    private const string NONE_COLUMN = '';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AllocationsCapabilitiesProvider $capabilitiesProvider,
        private readonly ExplorerConfigPreviewFactory $previewFactory,
        private readonly ExplorerColumnGrainResolver $columnGrainResolver,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private readonly ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly AnalysisAxisResolver $axisResolver,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $locale */
        $locale = $options['locale'];

        $builder
            ->add('scopePeriod', StatisticsScopePeriodType::class, [
                'side' => StatisticsFilterSide::Primary,
                'locale' => $locale,
                'scope_choice_policy' => StatisticsFilterScopeChoicePolicy::AllocationStatistics,
            ])
            ->add('rowDimension', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.rows', 'choices' => []])
            ->add('rowGrain', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.row_grain', 'choices' => []])
            ->add('columnDimension', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.columns', 'choices' => []])
            ->add('columnGrain', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.column_grain', 'choices' => []])
            ->add('metric', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.metric', 'choices' => []])
            ->add('showPercentOfTotal', CheckboxType::class, [
                'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
                'required' => false,
            ])
            ->add('chartType', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.chart_type', 'choices' => []])
            ->add('tableLayout', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.table_layout', 'choices' => []])
            ->add('chartRowLimit', ChoiceType::class, ['label' => 'stats.generic_analysis.table.row_limit_label', 'choices' => []])
        ;

        $scopePeriodField = $builder->get('scopePeriod');
        $scopePeriodField->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            if (isset($data['scopeDetail']) && (\is_int($data['scopeDetail']) || \is_float($data['scopeDetail']))) {
                $data['scopeDetail'] = (string) $data['scopeDetail'];
                $event->setData($data);
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $submitted = $event->getData();
            if (!\is_array($submitted)) {
                return;
            }

            $current = $event->getForm()->getData();
            if (!$current instanceof ExplorerEditFormData) {
                return;
            }

            $preview = $this->previewFromSubmitted($submitted, $current);

            /** @var \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form */
            $form = $event->getForm();
            $this->configureDynamicChoices($form, $preview);
        });

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $formData = $event->getData();
            if (!$formData instanceof ExplorerEditFormData) {
                return;
            }

            /** @var \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form */
            $form = $event->getForm();
            $this->configureDynamicChoices($form, $formData);
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExplorerEditFormData::class,
            'locale' => 'en',
            'csrf_protection' => false,
        ]);

        $resolver->setAllowedTypes('locale', 'string');
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function previewFromSubmitted(array $submitted, ExplorerEditFormData $current): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $current->scopePeriod,
            rowDimension: \is_string($submitted['rowDimension'] ?? null) ? $submitted['rowDimension'] : $current->rowDimension,
            rowGrain: \array_key_exists('rowGrain', $submitted)
                ? (\is_string($submitted['rowGrain']) ? $submitted['rowGrain'] : null)
                : $current->rowGrain,
            columnDimension: \array_key_exists('columnDimension', $submitted)
                ? (\is_string($submitted['columnDimension']) ? $submitted['columnDimension'] : null)
                : $current->columnDimension,
            columnGrain: \array_key_exists('columnGrain', $submitted)
                ? (\is_string($submitted['columnGrain']) ? $submitted['columnGrain'] : null)
                : $current->columnGrain,
            metric: \is_string($submitted['metric'] ?? null) ? $submitted['metric'] : $current->metric,
            showPercentOfTotal: (bool) ($submitted['showPercentOfTotal'] ?? $current->showPercentOfTotal),
            chartType: \is_string($submitted['chartType'] ?? null) ? $submitted['chartType'] : $current->chartType,
            tableLayout: \is_string($submitted['tableLayout'] ?? null) ? $submitted['tableLayout'] : $current->tableLayout,
            chartRowLimit: \is_string($submitted['chartRowLimit'] ?? null) ? $submitted['chartRowLimit'] : $current->chartRowLimit,
        );
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form
     */
    private function configureDynamicChoices(\Symfony\Component\Form\FormInterface $form, ExplorerEditFormData $formData): void
    {
        $user = $this->security->getUser();
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user instanceof User ? $user : null,
        );
        $capabilities = $this->capabilitiesProvider->capabilitiesFor(
            $user instanceof User ? $user : null,
            $filter,
        );

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        $formData = $this->withResolvedColumnGrain($form, $formData, $rowAxis, $capabilities);
        $columnAxis = $this->resolveColumnAxis($formData, $rowAxis, $capabilities);
        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::AllocationCount;
        $previewConfig = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);

        $form->add('rowDimension', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.row_dimension',
            'help' => 'stats.analysis_explorer.edit.row_dimension_help',
            'choices' => $this->dimensionChoices($capabilities->dimensions),
        ]);

        $form->add('rowGrain', ChoiceType::class, [
            'label' => $rowAxis->dimensionKey->isTemporalPrimary()
                ? 'stats.analysis_explorer.edit.time_grain'
                : 'stats.analysis_explorer.edit.group_by',
            'help' => $rowAxis->dimensionKey->isTemporalPrimary()
                ? 'stats.analysis_explorer.edit.row_grain_time_help'
                : 'stats.analysis_explorer.edit.row_grain_breakdown_help',
            'choices' => $this->grainChoices($rowAxis->dimensionKey, $capabilities),
        ]);

        $form->add('columnDimension', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.column_dimension',
            'help' => 'stats.analysis_explorer.edit.column_dimension_help',
            'choices' => $this->columnDimensionChoices($rowAxis, $capabilities),
        ]);

        $columnGrainChoices = [];
        $showColumnGrain = false;
        if ($columnAxis instanceof AnalysisAxisRef && $this->columnGrainResolver->affectsQuery($columnAxis->dimensionKey)) {
            $showColumnGrain = true;
            $columnGrainChoices = $this->grainChoices($columnAxis->dimensionKey, $capabilities);
        }

        $form->add('columnGrain', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.column_grain',
            'help' => 'stats.analysis_explorer.edit.column_grain_help',
            'choices' => $columnGrainChoices,
            'disabled' => !$showColumnGrain,
        ]);

        $form->add('metric', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_metric',
            'help' => 'stats.analysis_explorer.edit.chart_metric_help',
            'choices' => $this->metricChoices($capabilities->primaryMetrics),
        ]);

        $form->add('showPercentOfTotal', CheckboxType::class, [
            'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
            'help' => 'stats.analysis_explorer.edit.show_percent_of_total_help',
            'required' => false,
            'disabled' => !$this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig),
        ]);

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig);
        if (!\in_array($chartType, $allowedChartTypes, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
            $currentData = $form->getData();
            if ($currentData instanceof ExplorerEditFormData) {
                $form->setData(new ExplorerEditFormData(
                    scopePeriod: $currentData->scopePeriod,
                    rowDimension: $currentData->rowDimension,
                    rowGrain: $currentData->rowGrain,
                    columnDimension: $currentData->columnDimension,
                    columnGrain: $currentData->columnGrain,
                    metric: $currentData->metric,
                    showPercentOfTotal: $currentData->showPercentOfTotal,
                    chartType: $chartType->value,
                    tableLayout: $currentData->tableLayout,
                    chartRowLimit: $currentData->chartRowLimit,
                ));
            }
        }

        $form->add('chartType', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_type',
            'choices' => $this->chartTypeChoices($allowedChartTypes),
        ]);

        $form->add('tableLayout', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.table_layout',
            'help' => 'stats.analysis_explorer.edit.table_layout_help',
            'choices' => $this->tableLayoutChoices(),
            'disabled' => !$columnAxis instanceof AnalysisAxisRef,
        ]);

        $form->add('chartRowLimit', ChoiceType::class, [
            'label' => 'stats.generic_analysis.table.row_limit_label',
            'choices' => $this->chartRowLimitChoices(),
            'disabled' => $rowAxis->dimensionKey->isTemporalPrimary(),
        ]);
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form
     */
    private function withResolvedColumnGrain(
        \Symfony\Component\Form\FormInterface $form,
        ExplorerEditFormData $formData,
        AnalysisAxisRef $rowAxis,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
    ): ExplorerEditFormData {
        if (null === $formData->columnDimension || self::NONE_COLUMN === $formData->columnDimension) {
            return $formData;
        }

        $columnDimension = AnalysisDimensionKey::tryFrom($formData->columnDimension);
        if (!$columnDimension instanceof AnalysisDimensionKey) {
            return $formData;
        }

        $submittedColumnGrain = \is_string($formData->columnGrain)
            ? AnalysisDimensionGrain::tryFrom($formData->columnGrain)
            : null;
        $resolvedColumnGrain = $this->columnGrainResolver->resolve(
            $rowAxis,
            $columnDimension,
            $submittedColumnGrain,
            $capabilities,
        );

        if ($formData->columnGrain === $resolvedColumnGrain->value) {
            return $formData;
        }

        $resolvedFormData = new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            rowDimension: $formData->rowDimension,
            rowGrain: $formData->rowGrain,
            columnDimension: $formData->columnDimension,
            columnGrain: $resolvedColumnGrain->value,
            metric: $formData->metric,
            showPercentOfTotal: $formData->showPercentOfTotal,
            chartType: $formData->chartType,
            tableLayout: $formData->tableLayout,
            chartRowLimit: $formData->chartRowLimit,
        );
        $form->setData($resolvedFormData);

        return $resolvedFormData;
    }

    private function resolveColumnAxis(
        ExplorerEditFormData $formData,
        AnalysisAxisRef $rowAxis,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
    ): ?AnalysisAxisRef {
        if (null === $formData->columnDimension || self::NONE_COLUMN === $formData->columnDimension) {
            return null;
        }

        $candidate = $this->axisResolver->resolveFromStrings(
            $formData->columnDimension,
            $formData->columnGrain,
            $capabilities,
        );

        if (!$capabilities->supportsColumnAxis($rowAxis, $candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param list<AnalysisDimensionKey> $dimensions
     *
     * @return array<string, string>
     */
    private function dimensionChoices(array $dimensions): array
    {
        $choices = [];
        foreach ($dimensions as $dimension) {
            $choices[$this->translator->trans('stats.analysis_explorer.dimension.'.$dimension->value)] = $dimension->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function columnDimensionChoices(
        AnalysisAxisRef $rowAxis,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
    ): array {
        $choices = [
            $this->translator->trans('stats.analysis_explorer.edit.columns_none') => self::NONE_COLUMN,
        ];

        foreach ($capabilities->columnDimensionsFor($rowAxis) as $dimension) {
            $choices[$this->translator->trans('stats.analysis_explorer.dimension.'.$dimension->value)] = $dimension->value;
        }

        return $choices;
    }

    /**
     * @param list<AnalysisMetricKey> $metrics
     *
     * @return array<string, string>
     */
    private function metricChoices(array $metrics): array
    {
        $choices = [];
        foreach ($metrics as $metric) {
            $choices[$this->translator->trans('stats.analysis_explorer.metric.'.$metric->value)] = $metric->value;
        }

        return $choices;
    }

    /**
     * @param list<ChartPresentationType> $chartTypes
     *
     * @return array<string, string>
     */
    private function chartTypeChoices(array $chartTypes): array
    {
        $choices = [];
        foreach ($chartTypes as $chartType) {
            $choices[$this->translator->trans('stats.analysis_explorer.chart.'.$chartType->value)] = $chartType->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function grainChoices(
        AnalysisDimensionKey $dimension,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
    ): array {
        $choices = [];
        foreach ($capabilities->timeGrainsFor($dimension) as $grain) {
            $labelKey = match ($grain) {
                AnalysisDimensionGrain::Total => 'stats.analysis_explorer.grain.total',
                AnalysisDimensionGrain::Year => 'stats.analysis_explorer.dimension.year',
                AnalysisDimensionGrain::Quarter => 'stats.analysis_explorer.dimension.quarter',
                AnalysisDimensionGrain::Week => 'stats.analysis_explorer.dimension.week',
                default => 'stats.analysis_explorer.dimension.month',
            };
            $choices[$this->translator->trans($labelKey)] = $grain->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function tableLayoutChoices(): array
    {
        return [
            $this->translator->trans('stats.analysis_explorer.table_layout.flat') => TableLayout::Flat->value,
            $this->translator->trans('stats.analysis_explorer.table_layout.matrix') => TableLayout::Matrix->value,
            $this->translator->trans('stats.analysis_explorer.table_layout.matrix_metrics_as_rows') => TableLayout::MatrixMetricsAsRows->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function chartRowLimitChoices(): array
    {
        return [
            $this->translator->trans('stats.generic_analysis.table.row_limit_all') => ExplorerChartRowLimit::All->value,
            $this->translator->trans('stats.generic_analysis.table.row_limit_top_5') => ExplorerChartRowLimit::Top5->value,
            $this->translator->trans('stats.generic_analysis.table.row_limit_top_10') => ExplorerChartRowLimit::Top10->value,
        ];
    }
}
