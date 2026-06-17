<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

final readonly class IndicationCompareHeader
{
    public function __construct(
        public int $indicationIdA,
        public int $indicationIdB,
        public string $indicationLabelA,
        public string $indicationLabelB,
        public int $totalA,
        public int $totalB,
    ) {
    }
}
