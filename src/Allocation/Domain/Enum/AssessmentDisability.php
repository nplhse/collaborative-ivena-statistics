<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum AssessmentDisability: string
{
    case AWAKE = 'awake';
    case GCS_BELOW_15 = 'gcs_below_15';
    case GCS_BELOW_9 = 'gcs_below_9';
    case SEDATED = 'sedated';
    case ANESTHETIZED = 'anesthetized';

    public function getType(): string
    {
        return match ($this) {
            self::AWAKE => self::AWAKE->value,
            self::GCS_BELOW_15 => self::GCS_BELOW_15->value,
            self::GCS_BELOW_9 => self::GCS_BELOW_9->value,
            self::SEDATED => self::SEDATED->value,
            self::ANESTHETIZED => self::ANESTHETIZED->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::AWAKE->value,
            self::GCS_BELOW_15->value,
            self::GCS_BELOW_9->value,
            self::SEDATED->value,
            self::ANESTHETIZED->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::AWAKE => 'label.assessment.disability.awake',
            self::GCS_BELOW_15 => 'label.assessment.disability.gcs_below_15',
            self::GCS_BELOW_9 => 'label.assessment.disability.gcs_below_9',
            self::SEDATED => 'label.assessment.disability.sedated',
            self::ANESTHETIZED => 'label.assessment.disability.anesthetized',
        };
    }
}
