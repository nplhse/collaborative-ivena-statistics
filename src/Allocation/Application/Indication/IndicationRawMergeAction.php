<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

final readonly class IndicationRawMergeAction
{
    public function __construct(
        public string $type,
        public int $loserId,
        public ?int $survivorId,
        public int $code,
        public string $beforeName,
        public string $afterName,
        public int $allocationCount,
    ) {
    }
}
