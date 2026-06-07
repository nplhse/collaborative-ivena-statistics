<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowOriginSlice
{
    public function __construct(
        public int $dispatchAreaId,
        public string $originName,
        public int $caseCount,
        public int $emergencyCount,
        public bool $suppressed,
    ) {
    }
}
