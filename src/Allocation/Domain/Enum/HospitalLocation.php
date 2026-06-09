<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum HospitalLocation: string
{
    case RURAL = 'Rural';
    case MIXED = 'Mixed';
    case URBAN = 'Urban';

    public function getType(): string
    {
        return match ($this) {
            self::RURAL => self::RURAL->value,
            self::MIXED => self::MIXED->value,
            self::URBAN => self::URBAN->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::RURAL->value,
            self::MIXED->value,
            self::URBAN->value,
        ];
    }
}
