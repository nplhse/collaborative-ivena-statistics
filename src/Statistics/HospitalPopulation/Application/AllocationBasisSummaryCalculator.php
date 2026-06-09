<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationBasisSummary;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationCrossTable;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationCrossTableCell;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationCrossTableColumn;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationCrossTableRow;
use App\Statistics\HospitalPopulation\Application\DTO\AllocationGroupStats;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;

final readonly class AllocationBasisSummaryCalculator
{
    public function __construct(
        private DescriptiveStatisticsCalculator $descriptiveStatisticsCalculator,
    ) {
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     */
    public function calculate(array $snapshots): AllocationBasisSummary
    {
        $participants = array_values(array_filter(
            $snapshots,
            static fn (HospitalPopulationSnapshot $snapshot): bool => $snapshot->hasAllocations && $snapshot->allocationCount > 0,
        ));

        $allocationCounts = array_map(
            static fn (HospitalPopulationSnapshot $snapshot): int => $snapshot->allocationCount,
            $participants,
        );

        $totalAllocations = array_sum($allocationCounts);

        $enumLabel = static fn (string $key): string => $key;
        $sizeKeys = array_map(static fn (HospitalSize $size): string => $size->value, HospitalSize::cases());
        $tierKeys = array_map(static fn (HospitalTier $tier): string => $tier->value, HospitalTier::cases());
        $locationKeys = array_map(static fn (HospitalLocation $location): string => $location->value, HospitalLocation::cases());

        return new AllocationBasisSummary(
            bySize: $this->buildGroupedStats(
                $participants,
                $totalAllocations,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
                $sizeKeys,
                $enumLabel,
            ),
            byTier: $this->buildGroupedStats(
                $participants,
                $totalAllocations,
                static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
                $tierKeys,
                $enumLabel,
                skipUnresolvedKeys: true,
            ),
            byLocation: $this->buildGroupedStats(
                $participants,
                $totalAllocations,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->urbanity->value,
                $locationKeys,
                $enumLabel,
            ),
            sizeByTierCrossTable: $this->buildCrossTable(
                $participants,
                $totalAllocations,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->size->value,
                static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
                $sizeKeys,
                $tierKeys,
                $enumLabel,
            ),
            locationByTierCrossTable: $this->buildCrossTable(
                $participants,
                $totalAllocations,
                static fn (HospitalPopulationSnapshot $snapshot): string => $snapshot->urbanity->value,
                static fn (HospitalPopulationSnapshot $snapshot): ?string => $snapshot->careLevel?->value,
                $locationKeys,
                $tierKeys,
                $enumLabel,
            ),
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot>              $participants
     * @param list<string>                                  $orderedKeys
     * @param callable(HospitalPopulationSnapshot): ?string $keyResolver
     * @param callable(string): string                      $labelResolver
     *
     * @return list<AllocationGroupStats>
     */
    private function buildGroupedStats(
        array $participants,
        int $totalAllocations,
        callable $keyResolver,
        array $orderedKeys,
        callable $labelResolver,
        bool $skipUnresolvedKeys = false,
    ): array {
        /** @var array<string, list<int>> $groupedCounts */
        $groupedCounts = [];
        foreach ($orderedKeys as $key) {
            $groupedCounts[$key] = [];
        }

        foreach ($participants as $participant) {
            $key = $keyResolver($participant);
            if (null === $key) {
                continue;
            }

            if (!isset($groupedCounts[$key])) {
                if ($skipUnresolvedKeys) {
                    continue;
                }

                $groupedCounts[$key] = [];
            }

            $groupedCounts[$key][] = $participant->allocationCount;
        }

        $rows = [];
        foreach ($orderedKeys as $key) {
            $counts = $groupedCounts[$key] ?? [];
            $groupTotal = array_sum($counts);
            $stats = $this->descriptiveStatisticsCalculator->calculate($counts);

            $rows[] = new AllocationGroupStats(
                key: $key,
                label: $labelResolver($key),
                totalAllocations: $groupTotal,
                sharePercent: $this->sharePercent($groupTotal, $totalAllocations),
                hospitalCount: $stats->count,
                meanPerHospital: $stats->mean,
                medianPerHospital: $stats->median,
            );
        }

        return $rows;
    }

    /**
     * @param list<HospitalPopulationSnapshot>              $participants
     * @param list<string>                                  $rowKeys
     * @param list<string>                                  $columnKeys
     * @param callable(HospitalPopulationSnapshot): string  $rowKeyResolver
     * @param callable(HospitalPopulationSnapshot): ?string $columnKeyResolver
     * @param callable(string): string                      $labelResolver
     */
    private function buildCrossTable(
        array $participants,
        int $totalAllocations,
        callable $rowKeyResolver,
        callable $columnKeyResolver,
        array $rowKeys,
        array $columnKeys,
        callable $labelResolver,
    ): AllocationCrossTable {
        $columns = array_map(
            static fn (string $key): AllocationCrossTableColumn => new AllocationCrossTableColumn(
                key: $key,
                label: $labelResolver($key),
            ),
            $columnKeys,
        );

        /** @var array<string, array<string, list<int>>> $groupedCounts */
        $groupedCounts = [];
        foreach ($rowKeys as $rowKey) {
            $groupedCounts[$rowKey] = [];
            foreach ($columnKeys as $columnKey) {
                $groupedCounts[$rowKey][$columnKey] = [];
            }
        }

        foreach ($participants as $participant) {
            $rowKey = $rowKeyResolver($participant);
            $columnKey = $columnKeyResolver($participant);
            if (null === $columnKey || !isset($groupedCounts[$rowKey][$columnKey])) {
                continue;
            }

            $groupedCounts[$rowKey][$columnKey][] = $participant->allocationCount;
        }

        $rows = [];
        foreach ($rowKeys as $rowKey) {
            $cells = [];
            foreach ($columnKeys as $columnKey) {
                $counts = $groupedCounts[$rowKey][$columnKey];
                $cellTotal = array_sum($counts);
                $stats = $this->descriptiveStatisticsCalculator->calculate($counts);

                $cells[] = new AllocationCrossTableCell(
                    totalAllocations: $cellTotal,
                    hospitalCount: $stats->count,
                    meanPerHospital: $stats->mean,
                    sharePercent: $this->sharePercent($cellTotal, $totalAllocations),
                );
            }

            $rows[] = new AllocationCrossTableRow(
                key: $rowKey,
                label: $labelResolver($rowKey),
                cells: $cells,
            );
        }

        return new AllocationCrossTable(columns: $columns, rows: $rows);
    }

    private function sharePercent(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ((float) $value / (float) $total) * 100.0;
    }
}
