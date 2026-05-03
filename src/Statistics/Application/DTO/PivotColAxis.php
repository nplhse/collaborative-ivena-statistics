<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO;

enum PivotColAxis: string
{
    case Gender = 'gender';
    case Urgency = 'urgency';

    public static function tryFromQuery(?string $raw): ?self
    {
        $trimmed = trim((string) $raw);

        return match ($trimmed) {
            self::Gender->value => self::Gender,
            self::Urgency->value => self::Urgency,
            default => null,
        };
    }
}
