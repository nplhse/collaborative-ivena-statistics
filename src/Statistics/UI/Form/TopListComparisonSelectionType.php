<?php

declare(strict_types=1);

namespace App\Statistics\UI\Form;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionSideType;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterSide;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BenchmarkSelectionSideFormData>
 */
final class TopListComparisonSelectionType extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BenchmarkSelectionSideFormData::class,
            'side' => StatisticsFilterSide::Comparison,
            'hospital_permission' => HospitalPermission::Statistics,
            'translation_domain' => 'statistics',
            'csrf_protection' => false,
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return BenchmarkSelectionSideType::class;
    }
}
