<?php

declare(strict_types=1);

namespace App\Statistics\UI\Form;

use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionSideType;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Neutral wrapper around benchmark scope/period fields for statistics pages.
 *
 * @extends AbstractType<StatisticsScopePeriodFormData>
 */
final class StatisticsScopePeriodType extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StatisticsScopePeriodFormData::class,
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return BenchmarkSelectionSideType::class;
    }
}
