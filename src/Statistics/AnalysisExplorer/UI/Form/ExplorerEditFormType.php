<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\StatisticsScopePeriodType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
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
        $capabilities = $this->capabilitiesProvider->capabilities();

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
                'choices' => $this->metricChoices($capabilities->metrics),
            ])
            ->add('timeGrain', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.time_grain',
                'choices' => $this->timeGrainChoices(),
                'required' => false,
            ])
            ->add('chartType', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.chart_type',
                'choices' => $this->chartTypeChoices($capabilities->chartTypes),
            ])
        ;
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
     * @param list<\App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey> $metrics
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
     * @param list<\App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType> $chartTypes
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
    private function timeGrainChoices(): array
    {
        return [
            $this->translator->trans('stats.analysis_explorer.dimension.month') => 'month',
            $this->translator->trans('stats.analysis_explorer.dimension.year') => 'year',
        ];
    }
}
