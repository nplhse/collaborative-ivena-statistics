<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

final readonly class InsightSearchHit
{
    public function __construct(
        public InsightDimensionKey $dimension,
        public int $id,
        public string $label,
        public string $url,
        public ?string $contextLabel = null,
    ) {
    }
}
