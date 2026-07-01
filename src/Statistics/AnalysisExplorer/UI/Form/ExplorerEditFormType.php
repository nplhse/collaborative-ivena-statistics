<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AnalysisAxisResolver;
use App\Statistics\AnalysisExplorer\Application\AnalysisFilterChoiceProvider;
use App\Statistics\AnalysisExplorer\Application\DataSourceCapabilitiesRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerColumnGrainResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditChoicePresenter;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
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
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use App\Statistics\UI\Form\StatisticsScopePeriodType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
        private readonly ExplorerEditChoicePresenter $editChoicePresenter,
        private readonly ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly AnalysisAxisResolver $axisResolver,
        private readonly Security $security,
        private readonly AnalysisFilterChoiceProvider $filterChoiceProvider,
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
            ->add('rowDimension', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.rows', 'choices' => []])
            ->add('rowGrain', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.row_grain', 'choices' => []])
            ->add('columnDimension', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.columns', 'choices' => []])
            ->add('columnGrain', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.column_grain', 'choices' => []])
            ->add('metric', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.metric', 'choices' => []])
            ->add('showPercentOfTotal', CheckboxType::class, [
                'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
                'required' => false,
            ])
            ->add('chartType', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.chart_type', 'choices' => []])
            ->add('tableLayout', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.table_layout', 'choices' => []])
            ->add('chartRowLimit', PreTranslatedChoiceType::class, ['label' => 'stats.generic_analysis.table.row_limit_label', 'choices' => []])
            ->add('hospitalPopulation', PreTranslatedChoiceType::class, ['label' => 'stats.analysis_explorer.edit.hospital_population', 'choices' => []])
            ->add('additionalTableMetrics', PreTranslatedChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.additional_table_metrics',
                'choices' => [],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('filterDepartmentIds', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.department', 'choices' => [], 'multiple' => true, 'required' => false]))
            ->add('filterSpecialityIds', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.speciality', 'choices' => [], 'multiple' => true, 'required' => false]))
            ->add('filterUrgency', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.urgency', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterTransportType', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.transport_type', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterGender', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.gender', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterAgeGroup', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.age_group', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterResus', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.requires_resus', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterCpr', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.is_cpr', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterVentilation', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.is_ventilated', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
            ->add('filterAssignmentId', PreTranslatedChoiceType::class, $this->messagesField(['label' => 'label.assignment', 'choices' => [], 'required' => false, 'placeholder' => 'label.all']))
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

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($locale): void {
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
            $this->configureDynamicChoices($form, $preview, $locale);
        });

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($locale): void {
            $formData = $event->getData();
            if (!$formData instanceof ExplorerEditFormData) {
                return;
            }

            /** @var \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form */
            $form = $event->getForm();
            $event->setData($this->configureDynamicChoices($form, $formData, $locale));
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'statistics',
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
            filterDepartmentIds: $this->intListFromSubmitted($submitted, 'filterDepartmentIds', $current->filterDepartmentIds),
            filterSpecialityIds: $this->intListFromSubmitted($submitted, 'filterSpecialityIds', $current->filterSpecialityIds),
            filterUrgency: $this->nullableIntFromSubmitted($submitted, 'filterUrgency', $current->filterUrgency),
            filterTransportType: $this->nullableIntFromSubmitted($submitted, 'filterTransportType', $current->filterTransportType),
            filterGender: $this->nullableIntFromSubmitted($submitted, 'filterGender', $current->filterGender),
            filterAgeGroup: $this->nullableStringFromSubmitted($submitted, 'filterAgeGroup', $current->filterAgeGroup),
            filterResus: $this->nullableBoolFromSubmitted($submitted, 'filterResus', $current->filterResus),
            filterCpr: $this->nullableBoolFromSubmitted($submitted, 'filterCpr', $current->filterCpr),
            filterVentilation: $this->nullableBoolFromSubmitted($submitted, 'filterVentilation', $current->filterVentilation),
            filterAssignmentId: $this->nullableIntFromSubmitted($submitted, 'filterAssignmentId', $current->filterAssignmentId),
        );
    }

    /**
     * @param array<string, mixed> $submitted
     * @param list<int>            $current
     *
     * @return list<int>
     */
    private function intListFromSubmitted(array $submitted, string $key, array $current): array
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $values = \is_array($submitted[$key]) ? $submitted[$key] : [];

        return array_values(array_map(intval(...), array_filter($values, static fn (mixed $value): bool => '' !== $value && null !== $value)));
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableIntFromSubmitted(array $submitted, string $key, ?int $current): ?int
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableStringFromSubmitted(array $submitted, string $key, ?string $current): ?string
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function nullableBoolFromSubmitted(array $submitted, string $key, ?bool $current): ?bool
    {
        if (!\array_key_exists($key, $submitted)) {
            return $current;
        }

        $value = $submitted[$key];
        if (null === $value || '' === $value) {
            return null;
        }

        return (bool) (int) $value;
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form
     */
    private function configureDynamicChoices(
        \Symfony\Component\Form\FormInterface $form,
        ExplorerEditFormData $formData,
        string $locale,
    ): ExplorerEditFormData {
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

        $form->add('rowDimension', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.row_dimension',
            'help' => 'stats.analysis_explorer.edit.row_dimension_help',
            'choices' => $this->editChoicePresenter->groupedDimensionChoices(
                $capabilities->dimensions,
                $dataSourceKey,
                $locale,
            ),
        ]);

        $form->add('rowGrain', PreTranslatedChoiceType::class, [
            'label' => $rowAxis->dimensionKey->isTemporalPrimary()
                ? 'stats.analysis_explorer.edit.time_grain'
                : 'stats.analysis_explorer.edit.group_by',
            'help' => $rowAxis->dimensionKey->isTemporalPrimary()
                ? 'stats.analysis_explorer.edit.row_grain_time_help'
                : 'stats.analysis_explorer.edit.row_grain_breakdown_help',
            'choices' => $this->grainChoices($rowAxis->dimensionKey, $capabilities),
        ]);

        $form->add('columnDimension', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.column_dimension',
            'help' => 'stats.analysis_explorer.edit.column_dimension_help',
            'choices' => $this->columnDimensionChoices($rowAxis, $capabilities, $dataSourceKey, $locale),
        ]);

        $columnGrainChoices = [];
        $showColumnGrain = false;
        if ($columnAxis instanceof AnalysisAxisRef && $this->columnGrainResolver->affectsQuery($columnAxis->dimensionKey)) {
            $showColumnGrain = true;
            $columnGrainChoices = $this->grainChoices($columnAxis->dimensionKey, $capabilities);
        }

        $form->add('columnGrain', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.column_grain',
            'help' => 'stats.analysis_explorer.edit.column_grain_help',
            'choices' => $columnGrainChoices,
            'disabled' => !$showColumnGrain,
        ]);

        $groupedMetricChoices = $this->editChoicePresenter->groupedMetricChoices(
            $compatibleMetrics,
            $dataSourceKey,
            $locale,
        );

        $form->add('metric', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_metric',
            'help' => 'stats.analysis_explorer.edit.chart_metric_help',
            'choices' => $groupedMetricChoices,
        ]);

        $form->add('showPercentOfTotal', CheckboxType::class, [
            'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
            'help' => 'stats.analysis_explorer.edit.show_percent_of_total_help',
            'required' => false,
            'disabled' => $isDistributionProfile || !$this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig),
        ]);

        $groupedAdditionalMetricChoices = $this->editChoicePresenter->groupedMetricChoices(
            $additionalMetricChoices,
            $dataSourceKey,
            $locale,
        );

        $form->add('additionalTableMetrics', ExplorerAdditionalTableMetricsType::class, [
            'label' => 'stats.analysis_explorer.edit.additional_table_metrics',
            'help' => 'stats.analysis_explorer.edit.additional_table_metrics_help',
            'choices' => $this->flattenGroupedChoices($groupedAdditionalMetricChoices),
            'explorer_metric_groups' => $groupedAdditionalMetricChoices,
            'disabled' => $isDistributionProfile || AnalysisDataSourceKey::Hospitals !== $dataSourceKey || [] === $additionalMetricChoices,
        ]);

        $allowedChartTypes = $capabilities->chartTypesFor($previewConfig);
        $chartType = $isDistributionProfile
            ? ChartPresentationType::BoxPlot
            : (ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig));
        if (!\in_array($chartType, $allowedChartTypes, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
            $formData = $this->rebuildFormData($formData, [
                'chartType' => $chartType->value,
                'additionalTableMetrics' => $isDistributionProfile ? [] : $formData->additionalTableMetrics,
            ]);
        } elseif ($isDistributionProfile) {
            $formData = $this->rebuildFormData($formData, [
                'showPercentOfTotal' => false,
                'chartType' => ChartPresentationType::BoxPlot->value,
                'additionalTableMetrics' => [],
            ]);
        }

        $form->add('chartType', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_type',
            'choices' => $this->chartTypeChoices($allowedChartTypes),
            'disabled' => $isDistributionProfile,
        ]);

        $form->add('tableLayout', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.table_layout',
            'help' => 'stats.analysis_explorer.edit.table_layout_help',
            'choices' => $this->tableLayoutChoices(),
            'disabled' => !$columnAxis instanceof AnalysisAxisRef,
        ]);

        $form->add('chartRowLimit', PreTranslatedChoiceType::class, [
            'label' => 'stats.generic_analysis.table.row_limit_label',
            'choices' => $this->chartRowLimitChoices(),
            'disabled' => $rowAxis->dimensionKey->isTemporalPrimary(),
        ]);

        $form->add('hospitalPopulation', PreTranslatedChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.hospital_population',
            'help' => 'stats.analysis_explorer.edit.hospital_population_help',
            'choices' => $this->hospitalPopulationChoices(),
            'disabled' => AnalysisDataSourceKey::Hospitals !== $dataSourceKey,
        ]);

        $this->configureFilterFields($form, $rowAxis, $columnAxis, $dataSourceKey);

        return $formData;
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<ExplorerEditFormData> $form
     */
    private function configureFilterFields(
        \Symfony\Component\Form\FormInterface $form,
        AnalysisAxisRef $rowAxis,
        ?AnalysisAxisRef $columnAxis,
        AnalysisDataSourceKey $dataSourceKey,
    ): void {
        $disabled = AnalysisDataSourceKey::Allocations !== $dataSourceKey;
        $axisKeys = array_filter([
            $rowAxis->toRegistryKey(),
            $columnAxis?->toRegistryKey(),
        ]);
        $isAxis = static fn (string $key): bool => \in_array($key, $axisKeys, true);
        $booleanChoices = [
            $this->translator->trans('label.yes', [], 'messages') => 1,
            $this->translator->trans('label.no', [], 'messages') => 0,
        ];

        $form->add('filterDepartmentIds', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.department',
            'choices' => $this->flipChoices($this->filterChoiceProvider->departmentChoices()),
            'multiple' => true,
            'required' => false,
            'disabled' => $disabled || $isAxis('department'),
        ]));
        $form->add('filterSpecialityIds', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.speciality',
            'choices' => $this->flipChoices($this->filterChoiceProvider->specialityChoices()),
            'multiple' => true,
            'required' => false,
            'disabled' => $disabled || $isAxis('speciality'),
        ]));
        $form->add('filterUrgency', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.urgency',
            'choices' => $this->flipChoices($this->filterChoiceProvider->urgencyChoices()),
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('urgency'),
        ]));
        $form->add('filterTransportType', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.transport_type',
            'choices' => $this->flipChoices($this->filterChoiceProvider->transportTypeChoices()),
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('transport_type'),
        ]));
        $form->add('filterGender', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.gender',
            'choices' => $this->flipChoices($this->filterChoiceProvider->genderChoices()),
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('gender'),
        ]));
        $form->add('filterAgeGroup', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.age_group',
            'choices' => $this->flipChoices($this->filterChoiceProvider->ageGroupChoices()),
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('age_group'),
        ]));
        $form->add('filterResus', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.requires_resus',
            'choices' => $booleanChoices,
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('resus'),
        ]));
        $form->add('filterCpr', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.is_cpr',
            'choices' => $booleanChoices,
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('cpr'),
        ]));
        $form->add('filterVentilation', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.is_ventilated',
            'choices' => $booleanChoices,
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('ventilation'),
        ]));
        $form->add('filterAssignmentId', PreTranslatedChoiceType::class, $this->messagesField([
            'label' => 'label.assignment',
            'choices' => $this->flipChoices($this->filterChoiceProvider->assignmentChoices()),
            'required' => false,
            'placeholder' => 'label.all',
            'disabled' => $disabled || $isAxis('assignment'),
        ]));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function messagesField(array $options): array
    {
        return array_merge($options, [
            'translation_domain' => 'messages',
            'choice_translation_domain' => false,
        ]);
    }

    /**
     * @param array<int|string, string> $valueToLabel
     *
     * @return array<string, int|string>
     */
    private function flipChoices(array $valueToLabel): array
    {
        $choices = [];
        foreach ($valueToLabel as $value => $label) {
            $choices[$label] = $value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function hospitalPopulationChoices(): array
    {
        $choices = [];
        foreach (ExplorerHospitalPopulationMode::cases() as $mode) {
            $choices[$this->translator->trans($mode->labelTranslationKey(), [], 'statistics')] = $mode->value;
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

        return $this->rebuildFormData($formData, ['columnGrain' => $resolvedColumnGrain->value]);
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
     * @return array<string, array<string, string>|string>
     */
    private function columnDimensionChoices(
        AnalysisAxisRef $rowAxis,
        \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities,
        AnalysisDataSourceKey $dataSourceKey,
        string $locale,
    ): array {
        return array_merge(
            [
                $this->translator->trans('stats.analysis_explorer.edit.columns_none', [], 'statistics') => self::NONE_COLUMN,
            ],
            $this->editChoicePresenter->groupedDimensionChoices(
                $capabilities->columnDimensionsFor($rowAxis),
                $dataSourceKey,
                $locale,
            ),
        );
    }

    /**
     * @param array<string, array<string, string>> $groupedChoices
     *
     * @return array<string, string>
     */
    private function flattenGroupedChoices(array $groupedChoices): array
    {
        $flat = [];
        foreach ($groupedChoices as $choices) {
            foreach ($choices as $label => $value) {
                $flat[$label] = $value;
            }
        }

        return $flat;
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
            $choices[$this->translator->trans('stats.analysis_explorer.chart.'.$chartType->value, [], 'statistics')] = $chartType->value;
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
            $choices[$this->translator->trans($labelKey, [], 'statistics')] = $grain->value;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function tableLayoutChoices(): array
    {
        return [
            $this->translator->trans('stats.analysis_explorer.table_layout.flat', [], 'statistics') => TableLayout::Flat->value,
            $this->translator->trans('stats.analysis_explorer.table_layout.matrix', [], 'statistics') => TableLayout::Matrix->value,
            $this->translator->trans('stats.analysis_explorer.table_layout.matrix_metrics_as_rows', [], 'statistics') => TableLayout::MatrixMetricsAsRows->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function chartRowLimitChoices(): array
    {
        return [
            $this->translator->trans('stats.generic_analysis.table.row_limit_all', [], 'statistics') => ExplorerChartRowLimit::All->value,
            $this->translator->trans('stats.generic_analysis.table.row_limit_top_5', [], 'statistics') => ExplorerChartRowLimit::Top5->value,
            $this->translator->trans('stats.generic_analysis.table.row_limit_top_10', [], 'statistics') => ExplorerChartRowLimit::Top10->value,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function rebuildFormData(ExplorerEditFormData $source, array $overrides): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $overrides['scopePeriod'] ?? $source->scopePeriod,
            dataSource: $overrides['dataSource'] ?? $source->dataSource,
            rowDimension: $overrides['rowDimension'] ?? $source->rowDimension,
            rowGrain: \array_key_exists('rowGrain', $overrides) ? $overrides['rowGrain'] : $source->rowGrain,
            columnDimension: \array_key_exists('columnDimension', $overrides) ? $overrides['columnDimension'] : $source->columnDimension,
            columnGrain: \array_key_exists('columnGrain', $overrides) ? $overrides['columnGrain'] : $source->columnGrain,
            metric: $overrides['metric'] ?? $source->metric,
            showPercentOfTotal: $overrides['showPercentOfTotal'] ?? $source->showPercentOfTotal,
            chartType: $overrides['chartType'] ?? $source->chartType,
            tableLayout: $overrides['tableLayout'] ?? $source->tableLayout,
            chartRowLimit: $overrides['chartRowLimit'] ?? $source->chartRowLimit,
            hospitalPopulation: $overrides['hospitalPopulation'] ?? $source->hospitalPopulation,
            additionalTableMetrics: $overrides['additionalTableMetrics'] ?? $source->additionalTableMetrics,
            filterDepartmentIds: $source->filterDepartmentIds,
            filterSpecialityIds: $source->filterSpecialityIds,
            filterUrgency: $source->filterUrgency,
            filterTransportType: $source->filterTransportType,
            filterGender: $source->filterGender,
            filterAgeGroup: $source->filterAgeGroup,
            filterResus: $source->filterResus,
            filterCpr: $source->filterCpr,
            filterVentilation: $source->filterVentilation,
            filterAssignmentId: $source->filterAssignmentId,
        );
    }
}
