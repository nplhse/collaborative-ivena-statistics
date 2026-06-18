<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\UI\Application\StatisticsFilterSide;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BenchmarkSelectionFormData>
 */
final class BenchmarkSelectionType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $locale */
        $locale = $options['locale'];

        $builder
            ->add('primary', BenchmarkSelectionSideType::class, [
                'label' => 'stats.benchmark.primary.label',
                'side' => StatisticsFilterSide::Primary,
                'locale' => $locale,
            ])
            ->add('comparison', BenchmarkSelectionSideType::class, [
                'label' => 'stats.benchmark.comparison.label',
                'side' => StatisticsFilterSide::Comparison,
                'locale' => $locale,
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BenchmarkSelectionFormData::class,
            'locale' => 'en',
            // Navigation-only form: builds GET query params for a redirect, no server-side mutation.
            // CSRF is disabled because Live Component POST actions (apply) do not trigger the
            // csrf-protection Stimulus controller, which breaks SameOriginCsrfTokenManager after login.
            'csrf_protection' => false,
        ]);

        $resolver->setAllowedTypes('locale', 'string');
    }
}
