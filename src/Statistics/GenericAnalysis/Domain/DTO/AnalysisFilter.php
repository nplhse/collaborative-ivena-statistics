<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;

final readonly class AnalysisFilter
{
    /**
     * @param int|string|bool|list<int|string|bool> $value
     */
    public function __construct(
        public string $dimensionKey,
        public AnalysisFilterOperator $operator,
        public int|string|bool|array $value,
    ) {
    }
}
