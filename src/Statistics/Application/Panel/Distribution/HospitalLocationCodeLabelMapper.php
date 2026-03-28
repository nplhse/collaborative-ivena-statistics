<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalLocationCodeLabelMapper implements CodeLabelMapperInterface
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $code): string
    {
        if (null === $code) {
            return $this->translator->trans('statistics.distribution.location_not_set');
        }

        $locCode = AllocationStatsHospitalLocationProjectionCode::tryFrom($code);
        if (!$locCode instanceof AllocationStatsHospitalLocationProjectionCode) {
            return $this->translator->trans('statistics.distribution.unknown_code');
        }

        $key = 'hospital.location.'.$locCode->toHospitalLocation()->value;

        return $this->translator->trans($key);
    }
}
