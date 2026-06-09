<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Http\Controller;

use App\Statistics\HospitalPopulation\Application\DTO\AllocationGroupStats;
use App\Statistics\HospitalPopulation\Application\DTO\BedsCategoryBoxPlotRow;
use App\Statistics\HospitalPopulation\Application\DTO\DescriptiveStats;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationDashboardResult;

final readonly class HospitalPopulationChartPayloadFactory
{
    /**
     * @return array{
     *     bedsBoxPlotByCareLevel: array{
     *         population: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>},
     *         participants: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>}
     *     },
     *     bedsBoxPlotByLocation: array{
     *         population: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>},
     *         participants: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>}
     *     },
     *     allocationByTier: array{categories: list<string>, series: list<array{name: string, data: list<int>}>},
     *     allocationBySize: array{categories: list<string>, series: list<array{name: string, data: list<int>}>},
     *     allocationByLocation: array{categories: list<string>, series: list<array{name: string, data: list<int>}>}
     * }
     */
    public function create(HospitalPopulationDashboardResult $result): array
    {
        $allocationBasis = $result->allocationBasis;

        return [
            'bedsBoxPlotByCareLevel' => $this->buildSplitCategoryBoxPlotPayload($result->bedsBoxPlotByCareLevel),
            'bedsBoxPlotByLocation' => $this->buildSplitCategoryBoxPlotPayload($result->bedsBoxPlotByLocation),
            'allocationByTier' => $this->buildGroupedBarPayload($allocationBasis->byTier, 'Allocations by tier'),
            'allocationBySize' => $this->buildGroupedBarPayload($allocationBasis->bySize, 'Allocations by size'),
            'allocationByLocation' => $this->buildGroupedBarPayload($allocationBasis->byLocation, 'Allocations by location'),
        ];
    }

    /**
     * @param list<AllocationGroupStats> $rows
     *
     * @return array{categories: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    private function buildGroupedBarPayload(array $rows, string $label): array
    {
        if ([] === $rows) {
            return [
                'categories' => [],
                'series' => [['name' => $label, 'data' => []]],
            ];
        }

        return [
            'categories' => array_map(static fn (AllocationGroupStats $row): string => $row->label, $rows),
            'series' => [[
                'name' => $label,
                'data' => array_map(static fn (AllocationGroupStats $row): int => $row->totalAllocations, $rows),
            ]],
        ];
    }

    /**
     * @param list<BedsCategoryBoxPlotRow> $rows
     *
     * @return array{
     *     population: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>},
     *     participants: array{series: list<array{name: string, type: string, data: list<array{x: string, y: list<float>}>}>}
     * }
     */
    private function buildSplitCategoryBoxPlotPayload(array $rows): array
    {
        $populationData = [];
        $participantData = [];

        foreach ($rows as $row) {
            $populationData[] = $this->boxPlotPoint($row->label, $row->population);
            $participantData[] = $this->boxPlotPoint($row->label, $row->participants);
        }

        return [
            'population' => [
                'series' => [
                    [
                        'name' => 'All hospitals',
                        'type' => 'boxPlot',
                        'data' => $populationData,
                    ],
                ],
            ],
            'participants' => [
                'series' => [
                    [
                        'name' => 'Participants',
                        'type' => 'boxPlot',
                        'data' => $participantData,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{x: string, y: list<float>}
     */
    private function boxPlotPoint(string $label, DescriptiveStats $stats): array
    {
        if (0 === $stats->count) {
            return ['x' => $label, 'y' => [0.0, 0.0, 0.0, 0.0, 0.0]];
        }

        return [
            'x' => $label,
            'y' => [
                (float) ($stats->minimum ?? 0),
                (float) ($stats->p25 ?? 0),
                (float) ($stats->median ?? 0),
                (float) ($stats->p75 ?? 0),
                (float) ($stats->maximum ?? 0),
            ],
        ];
    }
}
