<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalTierCodeLabelMapper implements CodeLabelMapperInterface
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
            return $this->translator->trans('statistics.distribution.tier_not_set');
        }

        $tierCode = AllocationStatsHospitalTierProjectionCode::tryFrom($code);
        if (!$tierCode instanceof AllocationStatsHospitalTierProjectionCode) {
            return $this->translator->trans('statistics.distribution.unknown_code');
        }

        $key = 'hospital.tier.'.$tierCode->toHospitalTier()->value;

        return $this->translator->trans($key);
    }
}
