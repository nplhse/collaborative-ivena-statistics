<?php

declare(strict_types=1);

namespace App\Enum;

enum AllocationTransportType: string
{
    case GROUND = 'G';
    case AIR = 'A';

    public function getType(): string
    {
        return match ($this) {
            self::GROUND => self::GROUND->value,
            self::AIR => self::AIR->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::GROUND->value,
            self::AIR->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::GROUND => 'label.transportType.ground',
            self::AIR => 'label.transportType.air',
        };
    }
}
