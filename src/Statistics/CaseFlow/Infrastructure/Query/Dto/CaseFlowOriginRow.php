<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query\Dto;

final readonly class CaseFlowOriginRow
{
    public function __construct(
        public int $dispatchAreaId,
        public string $originName,
        public int $caseCount,
        public int $emergencyCount,
    ) {
    }
}
