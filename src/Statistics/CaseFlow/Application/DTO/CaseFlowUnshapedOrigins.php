<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

/**
 * Cases whose origin dispatch area has no polygon on the origin choropleth (#1405).
 *
 * They are part of the share denominator but are not drawn. Below the privacy threshold the exact count and
 * share are withheld ($suppressed), only the fact that such cases exist is shown.
 */
final readonly class CaseFlowUnshapedOrigins
{
    public function __construct(
        public int $caseCount = 0,
        public float $sharePercent = 0.0,
        public bool $suppressed = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->caseCount && !$this->suppressed;
    }
}
