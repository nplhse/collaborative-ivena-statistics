<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigPreviewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricCapabilityPolicy;
use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
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
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AllocationsCapabilitiesProvider $capabilitiesProvider,
        private readonly ExplorerConfigPreviewFactory $previewFactory,
        private readonly ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private readonly ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
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
            ->add('dimension', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.dimension',
                'choices' => [],
            ])
            ->add('metric', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.metric',
                'choices' => [],
            ])
            ->add('showPercentOfTotal', CheckboxType::class, [
                'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
                'required' => false,
            ])
            ->add('timeGrain', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.time_grain',
                'choices' => [],
            ])
            ->add('chartType', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.chart_type',
                'choices' => [],
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

            $preview = new ExplorerEditFormData(
                scopePeriod: $current->scopePeriod,
                dimension: \is_string($submitted['dimension'] ?? null) ? $submitted['dimension'] : $current->dimension,
                metric: \is_string($submitted['metric'] ?? null) ? $submitted['metric'] : $current->metric,
                showPercentOfTotal: (bool) ($submitted['showPercentOfTotal'] ?? $current->showPercentOfTotal),
                timeGrain: \array_key_exists('timeGrain', $submitted)
                    ? (\is_string($submitted['timeGrain']) ? $submitted['timeGrain'] : null)
                    : $current->timeGrain,
                chartType: \is_string($submitted['chartType'] ?? null) ? $submitted['chartType'] : $current->chartType,
            );

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
        $dimension = AnalysisDimensionKey::tryFrom($formData->dimension) ?? AnalysisDimensionKey::Time;
        $grain = AnalysisDimensionGrain::tryFrom($formData->timeGrain ?? '') ?? AnalysisDimensionGrain::Month;
        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::AllocationCount;
        $previewConfig = $this->previewFactory->fromFormData($capabilities, $dimension, $metric, $grain, $formData);

        $grainLabel = $dimension->isTemporalPrimary()
            ? 'stats.analysis_explorer.edit.time_grain'
            : 'stats.analysis_explorer.edit.group_by';

        $form->add('dimension', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.dimension',
            'choices' => $this->dimensionChoices($capabilities->dimensions),
        ]);

        $form->add('metric', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.metric',
            'choices' => $this->metricChoices($capabilities->primaryMetrics),
        ]);

        $form->add('showPercentOfTotal', CheckboxType::class, [
            'label' => 'stats.analysis_explorer.edit.show_percent_of_total',
            'required' => false,
            'disabled' => !$this->metricCapabilityPolicy->canShowPercentOfTotal($previewConfig),
        ]);

        $form->add('timeGrain', ChoiceType::class, [
            'label' => $grainLabel,
            'choices' => $this->timeGrainChoices($dimension, $capabilities),
        ]);

        $form->add('chartType', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_type',
            'choices' => $this->chartTypeChoices($capabilities->chartTypesFor($previewConfig)),
        ]);
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
    private function timeGrainChoices(
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
}
