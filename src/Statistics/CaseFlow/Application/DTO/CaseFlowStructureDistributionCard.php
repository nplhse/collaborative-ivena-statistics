<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowStructureDistributionCard
{
    /**
     * @param list<CaseFlowDistributionSegment> $segments
     */
    public function __construct(
        public string $titleTranslationKey,
        public array $segments,
    ) {
    }
}
