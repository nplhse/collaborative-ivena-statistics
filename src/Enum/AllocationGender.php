<?php

declare(strict_types=1);

namespace App\Enum;

enum AllocationGender: string
{
    case MALE = 'M';
    case FEMALE = 'F';
    case OTHER = 'X';

    public function getType(): string
    {
        return match ($this) {
            self::MALE => self::MALE->value,
            self::FEMALE => self::FEMALE->value,
            self::OTHER => self::OTHER->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::MALE->value,
            self::FEMALE->value,
            self::OTHER->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'label.gender.male',
            self::FEMALE => 'label.gender.female',
            self::OTHER => 'label.gender.other',
        };
    }
}
