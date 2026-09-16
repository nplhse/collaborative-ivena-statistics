<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

final readonly class InsightSubject
{
    public function __construct(
        public InsightDimensionKey $dimension,
        public int $id,
        public string $label,
        public InsightPopulationFilter $population,
        public ?int $code = null,
        public ?string $publicId = null,
        public ?string $contextLabel = null,
    ) {
    }
}
