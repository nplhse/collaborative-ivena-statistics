<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;

final readonly class BenchmarkIndicationMixRowViewModel
{
    public function __construct(
        public BenchmarkDistributionBucket $bucket,
        public ?string $insightsUrl,
    ) {
    }
}
