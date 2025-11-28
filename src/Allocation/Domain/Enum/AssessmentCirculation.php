<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Enum;

enum AssessmentCirculation: string
{
    case STABLE = 'stable';
    case STABLE_WITH_MEDICATION = 'medication';
    case UNSTABLE = 'unstable';
    case ONGOING_CPR = 'cpr';

    public function getType(): string
    {
        return match ($this) {
            self::STABLE => self::STABLE->value,
            self::STABLE_WITH_MEDICATION => self::STABLE_WITH_MEDICATION->value,
            self::UNSTABLE => self::UNSTABLE->value,
            self::ONGOING_CPR => self::ONGOING_CPR->value,
        };
    }

    /**
     * @return string[]
     */
    public static function getValues(): array
    {
        return [
            self::STABLE->value,
            self::STABLE_WITH_MEDICATION->value,
            self::UNSTABLE->value,
            self::ONGOING_CPR->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::STABLE => 'label.assessment.circulation.stable',
            self::STABLE_WITH_MEDICATION => 'label.assessment.circulation.stable_with_medication',
            self::UNSTABLE => 'label.assessment.circulation.unstable',
            self::ONGOING_CPR => 'label.assessment.circulation.ongoing_cpr',
        };
    }
}
