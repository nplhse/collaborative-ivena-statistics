<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationCompare\Dto;

final readonly class IndicationCompareDistributionRow
{
    public function __construct(
        public string $dimension,
        public string $bucketKey,
        public ?string $bucketLabel,
        public int $sideACount,
        public int $sideBCount,
    ) {
    }
}
