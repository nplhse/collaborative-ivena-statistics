<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\GeographicSegment;

final readonly class GeographicSegmentDistributionRow
{
    public function __construct(
        public string $labelTranslationKey,
        public int $count,
        public float $segmentPercent,
        public ?float $referencePercent = null,
        public ?float $deltaPp = null,
        public string $barClass = '',
    ) {
    }

    public static function fromCategory(
        string $labelTranslationKey,
        GeographicSegmentCategoryCount $counts,
        int $segmentTotal,
        int $referenceTotal,
        bool $compareToReference,
        string $barClass = '',
    ): self {
        $rawSegmentPercent = GeographicSegmentShareMath::percent($counts->segmentCount, $segmentTotal);
        $rawReferencePercent = $compareToReference
            ? GeographicSegmentShareMath::percent($counts->referenceCount, $referenceTotal)
            : null;
        $rawDeltaPp = $compareToReference
            ? GeographicSegmentShareMath::deltaPp($rawSegmentPercent, $rawReferencePercent)
            : null;

        return new self(
            $labelTranslationKey,
            $counts->segmentCount,
            GeographicSegmentShareMath::roundDisplay($rawSegmentPercent) ?? 0.0,
            GeographicSegmentShareMath::roundDisplay($rawReferencePercent),
            GeographicSegmentShareMath::roundDisplay($rawDeltaPp),
            $barClass,
        );
    }
}
