<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum HospitalLocation: string
{
    case URBAN = 'Urban';
    case MIXED = 'Mixed';
    case RURAL = 'Rural';

    public function getType(): string
    {
        return match ($this) {
            self::URBAN => self::URBAN->value,
            self::MIXED => self::MIXED->value,
            self::RURAL => self::RURAL->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::URBAN->value,
            self::MIXED->value,
            self::RURAL->value,
        ];
    }
}
