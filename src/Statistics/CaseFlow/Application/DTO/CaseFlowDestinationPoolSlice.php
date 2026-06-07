<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowDestinationPoolSlice
{
    public function __construct(
        public string $poolKey,
        public string $labelTranslationKey,
        public int $caseCount,
        public int $hospitalCount,
        public bool $suppressed,
    ) {
    }
}
