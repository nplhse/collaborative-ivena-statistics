<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare\DTO;

use App\Statistics\Application\Insights\InsightDimensionKey;

final readonly class InsightCompareSubjectPair
{
    public function __construct(
        public InsightDimensionKey $dimensionA,
        public int $idA,
        public InsightDimensionKey $dimensionB,
        public int $idB,
    ) {
    }

    public function isSameSubject(): bool
    {
        return $this->dimensionA === $this->dimensionB && $this->idA === $this->idB;
    }
}
