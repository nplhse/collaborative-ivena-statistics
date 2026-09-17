<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

final readonly class InsightValueRow
{
    public function __construct(
        public int $id,
        public string $label,
        public int $count,
        public string $url,
        public ?int $code = null,
        public ?string $publicId = null,
        public ?string $shareDisplay = null,
        public ?int $rank = null,
        public ?string $contextLabel = null,
        public ?string $exploreUrl = null,
    ) {
    }
}
