<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\StatisticsScopePeriodType;
use Symfony\Component\Form\AbstractType;
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
                'choices' => $this->dimensionChoices(),
            ])
            ->add('metric', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.metric',
                'choices' => $this->metricChoices($this->capabilitiesProvider->capabilities()->metrics),
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
        $capabilities = $this->capabilitiesProvider->capabilities();
        $dimension = AnalysisDimensionKey::tryFrom($formData->dimension) ?? AnalysisDimensionKey::Time;
        $grain = AnalysisDimensionGrain::tryFrom($formData->timeGrain ?? '') ?? AnalysisDimensionGrain::Month;
        $previewConfig = $this->previewConfig($formData, $dimension, $grain);

        $grainLabel = AnalysisDimensionKey::Time === $dimension
            ? 'stats.analysis_explorer.edit.time_grain'
            : 'stats.analysis_explorer.edit.group_by';

        $form->add('timeGrain', ChoiceType::class, [
            'label' => $grainLabel,
            'choices' => $this->timeGrainChoices($dimension),
        ]);

        $form->add('chartType', ChoiceType::class, [
            'label' => 'stats.analysis_explorer.edit.chart_type',
            'choices' => $this->chartTypeChoices($capabilities->chartTypesFor($previewConfig)),
        ]);
    }

    private function previewConfig(
        ExplorerEditFormData $formData,
        AnalysisDimensionKey $dimension,
        AnalysisDimensionGrain $grain,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::AllocationCount,
            dimensionKey: $dimension,
            timeGrain: $grain,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar,
            ),
            title: '',
        );
    }

    /**
     * @return array<string, string>
     */
    private function dimensionChoices(): array
    {
        return [
            $this->translator->trans('stats.analysis_explorer.dimension.time') => 'time',
            $this->translator->trans('stats.analysis_explorer.dimension.gender') => 'gender',
            $this->translator->trans('stats.analysis_explorer.dimension.urgency') => 'urgency',
        ];
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
    private function timeGrainChoices(AnalysisDimensionKey $dimension): array
    {
        $choices = [];
        foreach ($this->capabilitiesProvider->capabilities()->timeGrainsFor($dimension) as $grain) {
            $labelKey = match ($grain) {
                AnalysisDimensionGrain::Total => 'stats.analysis_explorer.grain.total',
                AnalysisDimensionGrain::Year => 'stats.analysis_explorer.dimension.year',
                default => 'stats.analysis_explorer.dimension.month',
            };
            $choices[$this->translator->trans($labelKey)] = $grain->value;
        }

        return $choices;
    }
}
