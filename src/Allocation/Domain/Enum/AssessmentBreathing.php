<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum AssessmentBreathing: string
{
    case SPONTANEOUS = 'spontaneous';
    case INSUFFICIENT = 'insufficient';
    case CPAP = 'cpap';
    case NIV = 'niv';
    case INVASIVE = 'invasive';

    public function getType(): string
    {
        return match ($this) {
            self::SPONTANEOUS => self::SPONTANEOUS->value,
            self::INSUFFICIENT => self::INSUFFICIENT->value,
            self::CPAP => self::CPAP->value,
            self::NIV => self::NIV->value,
            self::INVASIVE => self::INVASIVE->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::SPONTANEOUS->value,
            self::INSUFFICIENT->value,
            self::CPAP->value,
            self::NIV->value,
            self::INVASIVE->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::SPONTANEOUS => 'label.assessment.breathing.spontaneous',
            self::INSUFFICIENT => 'label.assessment.breathing.insufficient',
            self::CPAP => 'label.assessment.breathing.cpap',
            self::NIV => 'label.assessment.breathing.niv',
            self::INVASIVE => 'label.assessment.breathing.invasive',
        };
    }
}
