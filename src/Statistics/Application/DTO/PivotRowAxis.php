<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum PivotRowAxis: string
{
    case Department = 'department';
    case AgeGroup = 'age_group';
    case Urgency = 'urgency';

    public static function tryFromQuery(?string $raw): ?self
    {
        $trimmed = trim((string) $raw);

        return match ($trimmed) {
            self::Department->value => self::Department,
            self::AgeGroup->value => self::AgeGroup,
            self::Urgency->value => self::Urgency,
            default => null,
        };
    }
}
