<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

/**
 * The imports of one hospital that touch a period, sorted into real, generated (month markers) and scenario
 * imports (#877). Read by HospitalPeriodDataQuery; nothing here writes.
 *
 * A generated month is complete when it has its allocation and its closure import and both hold as many rows
 * as they announce; a process that was killed while writing leaves an incomplete month.
 */
final readonly class HospitalPeriodData
{
    /**
     * @param array<string, list<int>>                                                                                 $real      month => real imports with rows or an export period in it
     * @param array<string, list<array{id: int, type: string, seed: int, row_count: int, rows: int, projected: bool}>> $generated month => synthetic month imports of the hospital
     * @param list<array{id: int, months: list<string>}>                                                               $scenarios scenario imports with rows or a start day in the period
     */
    public function __construct(
        public array $real,
        public array $generated,
        public array $scenarios,
    ) {
    }

    /**
     * @return list<string>
     */
    public function realMonths(): array
    {
        $months = array_keys($this->real);
        sort($months);

        return $months;
    }

    public function hasGenerated(string $month): bool
    {
        return [] !== ($this->generated[$month] ?? []);
    }

    public function isComplete(string $month): bool
    {
        $types = [];
        foreach ($this->generated[$month] ?? [] as $import) {
            if ($import['rows'] !== $import['row_count']) {
                return false;
            }
            $types[$import['type']] = ($types[$import['type']] ?? 0) + 1;
        }

        return ['allocations' => 1, 'closures' => 1] == $types;
    }

    /**
     * @return list<int> seeds of the generated imports of the month
     */
    public function seeds(string $month): array
    {
        return array_values(array_unique(array_map(static fn (array $import): int => $import['seed'], $this->generated[$month] ?? [])));
    }

    /**
     * @return list<int>
     */
    public function generatedImportIds(string $month): array
    {
        return array_map(static fn (array $import): int => $import['id'], $this->generated[$month] ?? []);
    }

    /**
     * Allocation and closure import of a complete month whose allocations have no projection rows yet, for
     * example after a run whose process was killed before its asynchronous stage.
     *
     * @return array{allocations: int, closures: int}|null
     */
    public function unprojected(string $month): ?array
    {
        if (!$this->isComplete($month)) {
            return null;
        }
        $ids = [];
        $projected = true;
        foreach ($this->generated[$month] ?? [] as $import) {
            $ids[$import['type']] = $import['id'];
            if ('allocations' === $import['type'] && $import['rows'] > 0 && !$import['projected']) {
                $projected = false;
            }
        }

        return $projected ? null : ['allocations' => $ids['allocations'], 'closures' => $ids['closures']];
    }

    /**
     * @return list<string> months with scenario rows
     */
    public function scenarioMonths(): array
    {
        $months = [];
        foreach ($this->scenarios as $scenario) {
            array_push($months, ...$scenario['months']);
        }
        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }

    /**
     * @return list<int>
     */
    public function scenarioImportIds(): array
    {
        return array_map(static fn (array $scenario): int => $scenario['id'], $this->scenarios);
    }
}
