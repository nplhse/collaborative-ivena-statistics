<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Benchmarking\Application\BenchmarkMetricBuilder;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistribution;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkDistributionBucket;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class BenchmarkIndicationMixViewModelFactory
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    public function create(Request $request, BenchmarkDistribution $indicationMix): BenchmarkIndicationMixViewModel
    {
        $overRepresented = [];
        $underRepresented = [];

        foreach ($indicationMix->buckets as $bucket) {
            $row = new BenchmarkIndicationMixRowViewModel(
                $bucket,
                $this->insightsUrl($request, $bucket),
            );

            if ($bucket->ratio >= BenchmarkMetricBuilder::INDICATION_OVER_RATIO) {
                $overRepresented[] = $row;
                continue;
            }

            if ($bucket->ratio <= BenchmarkMetricBuilder::INDICATION_UNDER_RATIO) {
                $underRepresented[] = $row;
            }
        }

        return new BenchmarkIndicationMixViewModel($overRepresented, $underRepresented);
    }

    private function insightsUrl(Request $request, BenchmarkDistributionBucket $bucket): ?string
    {
        if (!ctype_digit($bucket->key)) {
            return null;
        }

        return $this->navigationUrlBuilder->build(
            $request,
            'app_stats_indication_dashboard',
            ['indicationId' => (int) $bucket->key],
        );
    }
}
