<?php

namespace App\Allocation\Domain\Enum;

enum AllocationUrgency: int
{
    case IMMEDIATE = 1;
    case URGENT = 2;
    case DELAYED = 3;

    public function getType(): int
    {
        return match ($this) {
            self::IMMEDIATE => self::IMMEDIATE->value,
            self::URGENT => self::URGENT->value,
            self::DELAYED => self::DELAYED->value,
        };
    }

    /**
     * @return int[]
     */
    public static function getValues(): array
    {
        return [
            self::IMMEDIATE->value,
            self::URGENT->value,
            self::DELAYED->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'label.urgency.immediate',
            self::URGENT => 'label.urgency.urgent',
            self::DELAYED => 'label.urgency.delayed',
        };
    }
}
