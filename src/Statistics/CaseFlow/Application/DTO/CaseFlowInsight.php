<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowInsight
{
    /**
     * @param array<string, int|float|string> $translationParams
     */
    public function __construct(
        public string $id,
        public CaseFlowInsightSeverity $severity,
        public string $translationKey,
        public array $translationParams,
        public int $sortScore,
        public ?string $badgeText = null,
    ) {
    }
}
