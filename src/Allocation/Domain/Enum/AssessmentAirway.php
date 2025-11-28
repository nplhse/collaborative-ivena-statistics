<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum AssessmentAirway: string
{
    case FREE = 'free';
    case AT_RISK = 'risk';
    case INTUBATED = 'intubated';
    case CRITICAL_AIRWAY = 'critical';

    public function getType(): string
    {
        return match ($this) {
            self::FREE => self::FREE->value,
            self::AT_RISK => self::AT_RISK->value,
            self::INTUBATED => self::INTUBATED->value,
            self::CRITICAL_AIRWAY => self::CRITICAL_AIRWAY->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::FREE->value,
            self::AT_RISK->value,
            self::INTUBATED->value,
            self::CRITICAL_AIRWAY->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::FREE => 'label.assessment.airway.free',
            self::AT_RISK => 'label.assessment.airway.at_risk',
            self::INTUBATED => 'label.assessment.airway.intubated',
            self::CRITICAL_AIRWAY => 'label.assessment.airway.critical',
        };
    }
}
