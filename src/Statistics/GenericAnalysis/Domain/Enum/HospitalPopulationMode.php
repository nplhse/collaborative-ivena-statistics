<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\Enum;

enum HospitalPopulationMode: string
{
    case All = 'all';
    case Participating = 'participating';
    case Compare = 'compare';

    public static function fromRequestValue(string $value): self
    {
        return match ($value) {
            self::Participating->value => self::Participating,
            self::Compare->value => self::Compare,
            default => self::All,
        };
    }
}
