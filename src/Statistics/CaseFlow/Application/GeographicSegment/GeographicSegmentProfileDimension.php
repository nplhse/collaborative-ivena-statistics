<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

enum GeographicSegmentProfileDimension: string
{
    case Overview = 'overview';
    case Age = 'age';
    case Resources = 'resources';
    case Features = 'features';

    public static function fromQueryValue(?string $raw): self
    {
        if (\in_array($raw, ['demographics', 'urgency', 'gender'], true)) {
            return self::Overview;
        }

        return self::tryFrom((string) $raw) ?? self::Overview;
    }

    public function titleTranslationKey(): string
    {
        return 'stats.case_flow.segment.tab.'.$this->value;
    }
}
