<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalLocationValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $location = null === $value ? null : AllocationStatsHospitalLocationProjectionCode::tryFrom($value);

        $key = match ($location) {
            AllocationStatsHospitalLocationProjectionCode::Urban => 'hospital.location.Urban',
            AllocationStatsHospitalLocationProjectionCode::Mixed => 'hospital.location.Mixed',
            AllocationStatsHospitalLocationProjectionCode::Rural => 'hospital.location.Rural',
            default => 'statistics.distribution.location_not_set',
        };

        return $this->translator->trans($key);
    }
}
