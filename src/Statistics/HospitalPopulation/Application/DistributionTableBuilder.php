<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTable;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTableCell;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTableColumn;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageCrossTableRow;
use App\Statistics\HospitalPopulation\Application\DTO\CoverageRow;
use App\Statistics\HospitalPopulation\Application\DTO\DistributionSummaryRow;
use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;
use App\Statistics\HospitalPopulation\Application\DTO\RegionalCoverageRow;

final readonly class DistributionTableBuilder
{
    public function __construct(
        private CoverageCalculator $coverageCalculator,
        private RepresentativityCalculator $representativityCalculator,
    ) {
    }

    /**
     * @param list<HospitalPopulationSnapshot>             $snapshots
     * @param callable(HospitalPopulationSnapshot): string $keyResolver
     * @param callable(string): string                     $labelResolver
     * @param list<string>                                 $orderedKeys
     *
     * @return list<CoverageRow>
     */
    public function buildCategoryTable(
        array $snapshots,
        callable $keyResolver,
        callable $labelResolver,
        array $orderedKeys,
    ): array {
        $populationCounts = [];
        $participantCounts = [];

        foreach ($orderedKeys as $key) {
            $populationCounts[$key] = 0;
            $participantCounts[$key] = 0;
        }

        foreach ($snapshots as $snapshot) {
            $key = $keyResolver($snapshot);
            if (!\array_key_exists($key, $populationCounts)) {
                $populationCounts[$key] = 0;
                $participantCounts[$key] = 0;
            }

            ++$populationCounts[$key];
            if ($snapshot->isParticipating) {
                ++$participantCounts[$key];
            }
        }

        $keys = [] !== $orderedKeys ? $orderedKeys : array_keys($populationCounts);
        $rows = [];

        foreach ($keys as $key) {
            $normalizedKey = $key;
            $population = $populationCounts[$normalizedKey] ?? $populationCounts[$key] ?? 0;
            $participants = $participantCounts[$normalizedKey] ?? $participantCounts[$key] ?? 0;

            $rows[] = new CoverageRow(
                key: $normalizedKey,
                label: $labelResolver($normalizedKey),
                population: $population,
                participants: $participants,
                coverage: $this->coverageCalculator->calculate($participants, $population),
            );
        }

        return $rows;
    }

    /**
     * @param list<HospitalPopulationSnapshot>              $snapshots
     * @param callable(HospitalPopulationSnapshot): ?string $rowKeyResolver
     * @param callable(HospitalPopulationSnapshot): string  $columnKeyResolver
     * @param list<string>                                  $rowKeys
     * @param list<string>                                  $columnKeys
     * @param callable(string): string                      $labelResolver
     */
    public function buildCrossTable(
        array $snapshots,
        callable $rowKeyResolver,
        callable $columnKeyResolver,
        array $rowKeys,
        array $columnKeys,
        callable $labelResolver,
    ): CoverageCrossTable {
        $columns = array_map(
            static fn (string $key): CoverageCrossTableColumn => new CoverageCrossTableColumn(
                key: $key,
                label: $labelResolver($key),
            ),
            $columnKeys,
        );

        /** @var array<string, array<string, array{population: int, participants: int}>> $counts */
        $counts = [];
        foreach ($rowKeys as $rowKey) {
            $counts[$rowKey] = [];
            foreach ($columnKeys as $columnKey) {
                $counts[$rowKey][$columnKey] = ['population' => 0, 'participants' => 0];
            }
        }

        foreach ($snapshots as $snapshot) {
            $rowKey = $rowKeyResolver($snapshot);
            if (null === $rowKey) {
                continue;
            }

            $columnKey = $columnKeyResolver($snapshot);
            if (!isset($counts[$rowKey][$columnKey])) {
                continue;
            }

            ++$counts[$rowKey][$columnKey]['population'];
            if ($snapshot->isParticipating) {
                ++$counts[$rowKey][$columnKey]['participants'];
            }
        }

        $rows = [];
        foreach ($rowKeys as $rowKey) {
            $cells = [];
            foreach ($columnKeys as $columnKey) {
                $population = $counts[$rowKey][$columnKey]['population'];
                $participants = $counts[$rowKey][$columnKey]['participants'];
                $cells[] = new CoverageCrossTableCell(
                    population: $population,
                    participants: $participants,
                    coverage: $this->coverageCalculator->calculate($participants, $population),
                );
            }

            $rows[] = new CoverageCrossTableRow(
                key: $rowKey,
                label: $labelResolver($rowKey),
                cells: $cells,
            );
        }

        return new CoverageCrossTable(columns: $columns, rows: $rows);
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     * @param list<CoverageRow>                $coverageRows
     *
     * @return list<DistributionSummaryRow>
     */
    public function buildDistributionSummaries(
        array $snapshots,
        array $coverageRows,
        int $totalPopulation,
        int $totalParticipants,
    ): array {
        unset($snapshots);

        $representativityRows = $this->representativityCalculator->fromCoverageRows(
            $coverageRows,
            $totalPopulation,
            $totalParticipants,
        );

        return array_map(
            static fn ($row, int $index): DistributionSummaryRow => new DistributionSummaryRow(
                key: $row->key,
                label: $row->label,
                population: $row->populationCount,
                participants: $row->participantCount,
                coverage: $coverageRows[$index]->coverage,
                populationSharePercent: $row->populationSharePercent,
                participantSharePercent: $row->participantSharePercent,
                deltaPercentPoints: $row->deltaPercentPoints,
            ),
            $representativityRows,
            array_keys($representativityRows),
        );
    }

    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     *
     * @return list<RegionalCoverageRow>
     */
    public function buildRegionalCoverageTable(array $snapshots): array
    {
        /** @var array<string, array{stateId: int, stateName: string, dispatchAreaId: int, dispatchAreaName: string, population: int, participants: int}> $aggregates */
        $aggregates = [];

        foreach ($snapshots as $snapshot) {
            $key = sprintf('%d:%d', $snapshot->stateId, $snapshot->dispatchAreaId);

            if (!isset($aggregates[$key])) {
                $aggregates[$key] = [
                    'stateId' => $snapshot->stateId,
                    'stateName' => $snapshot->stateName,
                    'dispatchAreaId' => $snapshot->dispatchAreaId,
                    'dispatchAreaName' => $snapshot->dispatchAreaName,
                    'population' => 0,
                    'participants' => 0,
                ];
            }

            ++$aggregates[$key]['population'];
            if ($snapshot->isParticipating) {
                ++$aggregates[$key]['participants'];
            }
        }

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $rows[] = new RegionalCoverageRow(
                stateId: $aggregate['stateId'],
                stateName: $aggregate['stateName'],
                dispatchAreaId: $aggregate['dispatchAreaId'],
                dispatchAreaName: $aggregate['dispatchAreaName'],
                population: $aggregate['population'],
                participants: $aggregate['participants'],
                coverage: $this->coverageCalculator->calculate($aggregate['participants'], $aggregate['population']),
            );
        }

        usort(
            $rows,
            static fn (RegionalCoverageRow $a, RegionalCoverageRow $b): int => $a->stateName <=> $b->stateName
                ?: $a->dispatchAreaName <=> $b->dispatchAreaName,
        );

        return $rows;
    }
}
