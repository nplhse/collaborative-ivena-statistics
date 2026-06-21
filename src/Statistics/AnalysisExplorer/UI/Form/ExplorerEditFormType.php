<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Form;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionSideType;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
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
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $locale */
        $locale = $options['locale'];

        $builder
            ->add('scopePeriod', BenchmarkSelectionSideType::class, [
                'side' => StatisticsFilterSide::Primary,
                'locale' => $locale,
                'scope_choice_policy' => StatisticsFilterScopeChoicePolicy::AllocationStatistics,
            ])
            ->add('dimensionGrain', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.dimension_grain',
                'choices' => [
                    $this->translator->trans('stats.analysis_explorer.dimension.month') => 'month',
                    $this->translator->trans('stats.analysis_explorer.dimension.year') => 'year',
                ],
            ])
            ->add('chartType', ChoiceType::class, [
                'label' => 'stats.analysis_explorer.edit.chart_type',
                'choices' => [
                    $this->translator->trans('stats.analysis_explorer.chart.bar') => 'bar',
                    $this->translator->trans('stats.analysis_explorer.chart.line') => 'line',
                ],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExplorerEditFormData::class,
            'locale' => 'en',
            // In-memory config edit via Live Component action (applyEdit), no traditional POST.
            // CSRF is disabled because Live Component actions do not trigger the
            // csrf-protection Stimulus controller, which breaks SameOriginCsrfTokenManager after login.
            'csrf_protection' => false,
        ]);

        $resolver->setAllowedTypes('locale', 'string');
    }
}
