<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode as TierCode;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HospitalTypeValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $tier = null === $value ? null : TierCode::tryFrom($value);

        $key = match ($tier) {
            TierCode::Basic => 'hospital.tier.Basic',
            TierCode::Extended => 'hospital.tier.Extended',
            TierCode::Full => 'hospital.tier.Full',
            default => 'statistics.distribution.tier_not_set',
        };

        return $this->translator->trans($key);
    }
}
