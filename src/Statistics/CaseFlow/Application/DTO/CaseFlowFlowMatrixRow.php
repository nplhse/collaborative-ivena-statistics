<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowFlowMatrixRow
{
    /**
     * @param array<string, int> $destinationCounts keyed by pool key (tier code as string)
     */
    public function __construct(
        public int $dispatchAreaId,
        public string $originName,
        public int $totalCases,
        public array $destinationCounts,
        public bool $suppressed,
    ) {
    }
}
