<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum ImportType: string
{
    case ALLOCATION = 'Allocation';

    case CLOSURE = 'Closure';

    public function getType(): string
    {
        return match ($this) {
            self::ALLOCATION => self::ALLOCATION->value,
            self::CLOSURE => self::CLOSURE->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::ALLOCATION->value,
            self::CLOSURE->value,
        ];
    }
}
