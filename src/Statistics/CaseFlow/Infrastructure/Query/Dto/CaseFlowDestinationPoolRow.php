<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query\Dto;

final readonly class CaseFlowDestinationPoolRow
{
    public function __construct(
        public string $poolKey,
        public int $caseCount,
        public int $hospitalCount,
    ) {
    }
}
