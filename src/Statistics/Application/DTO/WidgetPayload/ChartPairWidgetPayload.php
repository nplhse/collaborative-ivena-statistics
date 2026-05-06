<?php

declare(strict_types=1);

namespace App\Statistics\Application\DTO\WidgetPayload;

final readonly class ChartPairWidgetPayload implements WidgetPayloadInterface
{
    /**
     * @param array{labels: list<string>, monthlyCounts: list<int>, cumulativeCounts: list<int>} $allocationChart
     * @param array{labels: list<string>, monthlyCounts: list<int>}                               $importChart
     */
    public function __construct(
        private array $allocationChart,
        private array $importChart,
    ) {
    }

    public function toArray(): array
    {
        return [
            'allocationChart' => $this->allocationChart,
            'importChart' => $this->importChart,
        ];
    }
}
