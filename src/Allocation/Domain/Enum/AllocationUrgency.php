<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum AllocationUrgency: int
{
    case EMERGENCY = 1;
    case INPATIENT = 2;
    case OUTPATIENT = 3;

    public function getType(): int
    {
        return match ($this) {
            self::EMERGENCY => self::EMERGENCY->value,
            self::INPATIENT => self::INPATIENT->value,
            self::OUTPATIENT => self::OUTPATIENT->value,
        };
    }

    /**
     * @return int[]
     */
    public static function getValues(): array
    {
        return [
            self::EMERGENCY->value,
            self::INPATIENT->value,
            self::OUTPATIENT->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::EMERGENCY => 'label.urgency.emergency',
            self::INPATIENT => 'label.urgency.inpatient',
            self::OUTPATIENT => 'label.urgency.outpatient',
        };
    }

    public function skLabel(): string
    {
        return 'SK'.$this->value;
    }

    public static function tryFromQueryValue(mixed $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_string($value)) {
            $value = trim($value);
            if ('' === $value || !ctype_digit($value)) {
                return null;
            }
        } elseif (!\is_int($value)) {
            return null;
        }

        return self::tryFrom((int) $value);
    }
}
