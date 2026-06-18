<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

final readonly class HospitalInsight
{
    public function __construct(
        public string $title,
        public string $body,
        public HospitalInsightTrend $trend,
        public ?string $linkUrl = null,
    ) {
    }
}
