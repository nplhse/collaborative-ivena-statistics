<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\PanelDefinition;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DistributionDimensionValueMapperResolver
{
    /** @var array<string, TriStateBoolValueMapper> */
    private array $triStateMappers = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly TriageValueMapper $triageValueMapper,
        private readonly GenderValueMapper $genderValueMapper,
        private readonly AgeCohortValueMapper $ageCohortValueMapper,
        private readonly WeekdayValueMapper $weekdayValueMapper,
        private readonly HourOfDayValueMapper $hourOfDayValueMapper,
        private readonly TransportTimeBucketValueMapper $transportTimeBucketValueMapper,
        private readonly AssignmentDistributionNameMapper $assignmentDistributionNameMapper,
        private readonly OccasionDistributionNameMapper $occasionDistributionNameMapper,
    ) {
    }

    public function forPanel(PanelDefinition $panel): ValueMapper
    {
        if (DimensionKind::AgeCohort === $panel->dimensionKind) {
            return $this->ageCohortValueMapper;
        }

        return match ($panel->key) {
            'gender' => $this->genderValueMapper,
            'assignment' => $this->assignmentDistributionNameMapper,
            'occasion' => $this->occasionDistributionNameMapper,
            'weekday' => $this->weekdayValueMapper,
            'hour' => $this->hourOfDayValueMapper,
            'transport_time_bucket' => $this->transportTimeBucketValueMapper,
            'requires_resus' => $this->triState('statistics.distribution.tri.requires_resus'),
            'requires_cathlab' => $this->triState('statistics.distribution.tri.requires_cathlab'),
            'is_cpr' => $this->triState('statistics.distribution.tri.is_cpr'),
            'is_ventilated' => $this->triState('statistics.distribution.tri.is_ventilated'),
            'is_with_physician' => $this->triState('statistics.distribution.tri.is_with_physician'),
            default => $this->triageValueMapper,
        };
    }

    private function triState(string $prefix): TriStateBoolValueMapper
    {
        return $this->triStateMappers[$prefix] ??= new TriStateBoolValueMapper($this->translator, $prefix);
    }
}
