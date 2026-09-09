<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

final readonly class IndicationRawMergeResult
{
    /**
     * @param list<IndicationRawMergeAction> $actions
     * @param list<int>                      $affectedImportIds
     */
    public function __construct(
        public int $rehashed,
        public array $actions,
        public array $affectedImportIds,
    ) {
    }
}
