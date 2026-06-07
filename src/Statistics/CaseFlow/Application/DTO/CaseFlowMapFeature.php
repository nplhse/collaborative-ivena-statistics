<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowMapFeature
{
    public function __construct(
        public int $dispatchAreaId,
        public string $originName,
        public string $geoFeatureKey,
        public int $caseCount,
        public float $sharePercent,
        public bool $suppressed,
    ) {
    }
}
