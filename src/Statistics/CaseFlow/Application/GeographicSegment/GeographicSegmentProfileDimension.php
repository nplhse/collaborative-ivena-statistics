<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

enum GeographicSegmentProfileDimension: string
{
    case Overview = 'overview';
    case Urgency = 'urgency';
    case Demographics = 'demographics';
    case Resources = 'resources';

    public static function fromQueryValue(?string $raw): self
    {
        return self::tryFrom((string) $raw) ?? self::Overview;
    }

    public function titleTranslationKey(): string
    {
        return 'stats.case_flow.segment.tab.'.$this->value;
    }
}
