<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenderValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $gender = null === $value ? null : AllocationStatsGenderProjectionCode::tryFrom($value);

        $key = match ($gender) {
            AllocationStatsGenderProjectionCode::Male => 'label.gender.male',
            AllocationStatsGenderProjectionCode::Female => 'label.gender.female',
            AllocationStatsGenderProjectionCode::Other => 'label.gender.other',
            default => 'statistics.distribution.unknown_code',
        };

        return $this->translator->trans($key);
    }
}
