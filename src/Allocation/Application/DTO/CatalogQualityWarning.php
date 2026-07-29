<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckSeverity;

final readonly class CatalogQualityWarning
{
    public function __construct(
        public string $id,
        public string $labelKey,
        public int $count,
        public IndicationRawReviewHealthCheckSeverity $severity,
        public string $hintKey = '',
        public ?string $actionUrl = null,
    ) {
    }

    public function isFail(): bool
    {
        return IndicationRawReviewHealthCheckSeverity::Fail === $this->severity;
    }
}
