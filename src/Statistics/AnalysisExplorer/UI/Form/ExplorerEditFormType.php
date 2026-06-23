<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AnalysisAxisResolver;
use App\Statistics\AnalysisExplorer\Application\DataSourceCapabilitiesRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerColumnGrainResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricProfileRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
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
        private readonly DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private readonly ExplorerConfigPreviewFactory $previewFactory,
        private readonly ExplorerColumnGrainResolver $columnGrainResolver,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private readonly ExplorerMetricProfileRegistry $profileRegistry,
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
            ->add('hospitalPopulation', ChoiceType::class, ['label' => 'stats.analysis_explorer.edit.hospital_population', 'choices' => []])
            ->add('additionalTableMetrics', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.additional_table_metrics',
                'choices' => [],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
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
            $event->setData($this->configureDynamicChoices($form, $formData));
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
            dataSource: \is_string($submitted['dataSource'] ?? null) ? $submitted['dataSource'] : $current->dataSource,
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
            hospitalPopulation: \is_string($submitted['hospitalPopulation'] ?? null) ? $submitted['hospitalPopulation'] : $current->hospitalPopulation,
            additionalTableMetrics: \array_key_exists('additionalTableMetrics', $submitted)
                ? array_values(array_filter(
                    \is_array($submitted['additionalTableMetrics']) ? $submitted['additionalTableMetrics'] : [],
                    static fn (mixed $value): bool => \is_string($value) && '' !== $value,
                ))
                : $current->additionalTableMetrics,
        );
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form
     */
    private function configureDynamicChoices(\Symfony\Component\Form\FormInterface $form, ExplorerEditFormData $formData): ExplorerEditFormData
    {
        $user = $this->security->getUser();
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user instanceof User ? $user : null,
        );
        $dataSourceKey = AnalysisDataSourceKey::tryFrom($formData->dataSource) ?? AnalysisDataSourceKey::Allocations;
        $capabilities = $this->capabilitiesRegistry->capabilitiesFor(
            $dataSourceKey,
            $user instanceof User ? $user : null,
            $filter,
        );

        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );
        $formData = $this->withResolvedColumnGrain($formData, $rowAxis, $capabilities);
        $columnAxis = $this->resolveColumnAxis($formData, $rowAxis, $capabilities);
        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::defaultFor($dataSourceKey);
        $previewConfig = $this->previewFactory->fromFormData($capabilities, $rowAxis, $columnAxis, $metric, $formData);
        $compatibleMetrics = array_values(array_filter(
            $this->metricCapabilityPolicy->metricsForConfig($previewConfig),
            static fn (AnalysisMetricKey $key): bool => AnalysisMetricKey::PercentOfTotal !== $key,
        ));
        $isDistributionProfile = $metric->isDistributionProfile();
        $additionalMetricChoices = $isDistributionProfile
            ? []
            : array_values(array_filter(
                $compatibleMetrics,
                static fn (AnalysisMetricKey $key): bool => $key !== $metric,
            ));

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
            'choices' => $this->groupedMetricChoices($compatibleMetrics),
        ]);

        $form->add('showPercentOfTotal', CheckboxType::class, [
            'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
            'help' => 'stats.analysis_explorer.edit.show_percent_of_total_help',
            'required' => false,
            'disabled' => $isDistributionProfile || !$this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig),
        ]);

        $form->add('additionalTableMetrics', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.additional_table_metrics',
            'help' => 'stats.analysis_explorer.edit.additional_table_metrics_help',
            'choices' => $this->groupedMetricChoices($additionalMetricChoices),
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'disabled' => $isDistributionProfile || AnalysisDataSourceKey::Hospitals !== $dataSourceKey || [] === $additionalMetricChoices,
        ]);

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = $isDistributionProfile
            ? ChartPresentationType::BoxPlot
            : (ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig));
        if (!\in_array($chartType, $allowedChartTypes, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
            $formData = new ExplorerEditFormData(
                scopePeriod: $formData->scopePeriod,
                dataSource: $formData->dataSource,
                rowDimension: $formData->rowDimension,
                rowGrain: $formData->rowGrain,
                columnDimension: $formData->columnDimension,
                columnGrain: $formData->columnGrain,
                metric: $formData->metric,
                showPercentOfTotal: $formData->showPercentOfTotal,
                chartType: $chartType->value,
                tableLayout: $formData->tableLayout,
                chartRowLimit: $formData->chartRowLimit,
                hospitalPopulation: $formData->hospitalPopulation,
                additionalTableMetrics: $isDistributionProfile ? [] : $formData->additionalTableMetrics,
            );
        } elseif ($isDistributionProfile) {
            $formData = new ExplorerEditFormData(
                scopePeriod: $formData->scopePeriod,
                dataSource: $formData->dataSource,
                rowDimension: $formData->rowDimension,
                rowGrain: $formData->rowGrain,
                columnDimension: $formData->columnDimension,
                columnGrain: $formData->columnGrain,
                metric: $formData->metric,
                showPercentOfTotal: false,
                chartType: ChartPresentationType::BoxPlot->value,
                tableLayout: $formData->tableLayout,
                chartRowLimit: $formData->chartRowLimit,
                hospitalPopulation: $formData->hospitalPopulation,
                additionalTableMetrics: [],
            );
        }

        $form->add('chartType', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_type',
            'choices' => $this->chartTypeChoices($allowedChartTypes),
            'disabled' => $isDistributionProfile,
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

        $form->add('hospitalPopulation', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.hospital_population',
            'help' => 'stats.analysis_explorer.edit.hospital_population_help',
            'choices' => $this->hospitalPopulationChoices(),
            'disabled' => AnalysisDataSourceKey::Hospitals !== $dataSourceKey,
        ]);

        return $formData;
    }

    /**
     * @return array<string, string>
     */
    private function hospitalPopulationChoices(): array
    {
        $choices = [];
        foreach (ExplorerHospitalPopulationMode::cases() as $mode) {
            $choices[$this->translator->trans($mode->labelTranslationKey())] = $mode->value;
        }

        return $choices;
    }

    private function withResolvedColumnGrain(
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

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dataSource: $formData->dataSource,
            rowDimension: $formData->rowDimension,
            rowGrain: $formData->rowGrain,
            columnDimension: $formData->columnDimension,
            columnGrain: $resolvedColumnGrain->value,
            metric: $formData->metric,
            showPercentOfTotal: $formData->showPercentOfTotal,
            chartType: $formData->chartType,
            tableLayout: $formData->tableLayout,
            chartRowLimit: $formData->chartRowLimit,
            hospitalPopulation: $formData->hospitalPopulation,
            additionalTableMetrics: $formData->additionalTableMetrics,
        );
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
     * @return array<string, array<string, string>>
     */
    private function groupedMetricChoices(array $metrics): array
    {
        $grouped = [];
        foreach ($metrics as $metric) {
            $groupLabel = $this->translator->trans($this->metricGroupTranslationKey($metric));
            $grouped[$groupLabel][$this->metricChoiceLabel($metric)] = $metric->value;
        }

        return $grouped;
    }

    private function metricGroupTranslationKey(AnalysisMetricKey $metric): string
    {
        $profile = $this->profileRegistry->profileFor($metric);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $profile->groupTranslationKey;
        }

        return match ($metric) {
            AnalysisMetricKey::SumBeds,
            AnalysisMetricKey::AvgBeds,
            AnalysisMetricKey::MinBeds,
            AnalysisMetricKey::MaxBeds => 'stats.analysis_explorer.metric_group.beds',
            AnalysisMetricKey::TotalAllocations,
            AnalysisMetricKey::AvgAllocationsPerHospital,
            AnalysisMetricKey::MinAllocations,
            AnalysisMetricKey::MaxAllocations => 'stats.analysis_explorer.metric_group.allocations',
            default => 'stats.analysis_explorer.metric_group.counts',
        };
    }

    private function metricChoiceLabel(AnalysisMetricKey $metric): string
    {
        $profile = $this->profileRegistry->profileFor($metric);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey);
        }

        return $this->translator->trans('stats.analysis_explorer.metric.'.$metric->value);
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
