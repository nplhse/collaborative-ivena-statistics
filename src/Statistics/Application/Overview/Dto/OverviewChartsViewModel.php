<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

use App\Statistics\Application\IndicationDashboard\DTO\IndicationDistributionRow;

final readonly class OverviewChartsViewModel
{
    /**
     * @param array<string, mixed>            $chartPayload
     * @param list<IndicationDistributionRow> $ageGroupDistribution
     * @param list<IndicationDistributionRow> $transportDistribution
     * @param list<IndicationDistributionRow> $transportTimeDistribution
     */
    public function __construct(
        public array $chartPayload,
        public array $ageGroupDistribution,
        public array $transportDistribution,
        public array $transportTimeDistribution,
        public ?float $medianAge,
        public ?float $medianTransportMinutes,
    ) {
    }
}
