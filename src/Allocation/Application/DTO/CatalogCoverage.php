<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

/** @psalm-immutable */
final readonly class CatalogCoverage
{
    /**
     * @param list<array{year: int, count: int}> $years
     */
    public function __construct(
        public int $allocationCount,
        public int $totalAllocationCount,
        public int $hospitalCount,
        public int $dispatchAreaCount,
        public int $stateCount,
        public ?\DateTimeImmutable $firstAt,
        public ?\DateTimeImmutable $lastAt,
        public array $years,
        public bool $suppressed,
        public bool $revealSensitiveMetrics = true,
    ) {
    }

    public function hasData(): bool
    {
        if ($this->allocationCount > 0) {
            return true;
        }

        // Restricted hospital coverage keeps period/years without exposing volume.
        return !$this->revealSensitiveMetrics
            && ([] !== $this->years || ($this->firstAt instanceof \DateTimeImmutable && $this->lastAt instanceof \DateTimeImmutable));
    }

    public function sharePercent(): ?float
    {
        if ($this->suppressed || !$this->revealSensitiveMetrics || $this->totalAllocationCount <= 0) {
            return null;
        }

        return round((100.0 * (float) $this->allocationCount) / (float) $this->totalAllocationCount, 2);
    }

    /**
     * Year coverage as heatmap rows (default: 5 years per row, starting at 2015).
     *
     * @return list<list<array{year: int, count: int, intensity: float, future: bool, countHidden: bool, hasData: bool}>>
     */
    public function yearHeatmap(int $startYear = 2015, int $columns = 5, ?int $currentYear = null): array
    {
        if ($this->suppressed || [] === $this->years || $columns < 1) {
            return [];
        }

        $currentYear ??= (int) new \DateTimeImmutable('now')->format('Y');
        $byYear = [];
        $maxYear = $startYear;
        foreach ($this->years as $row) {
            $year = $row['year'];
            $byYear[$year] = $row['count'];
            $maxYear = max($maxYear, $year);
        }

        $span = $maxYear - $startYear + 1;
        $remainder = $span % $columns;
        if (0 !== $remainder) {
            $maxYear += $columns - $remainder;
        }

        $peak = max(1, ...array_values($byYear));
        $cells = [];
        for ($year = $startYear; $year <= $maxYear; ++$year) {
            $future = $year > $currentYear;
            $rawCount = $future ? 0 : ($byYear[$year] ?? 0);
            $hasData = $rawCount > 0;
            $countHidden = !$this->revealSensitiveMetrics && $hasData;
            $cells[] = [
                'year' => $year,
                'count' => $this->revealSensitiveMetrics ? $rawCount : 0,
                'intensity' => $hasData
                    ? ($this->revealSensitiveMetrics ? round($rawCount / $peak, 4) : 0.55)
                    : 0.0,
                'future' => $future,
                'countHidden' => $countHidden,
                'hasData' => $hasData,
            ];
        }

        /** @var list<list<array{year: int, count: int, intensity: float, future: bool, countHidden: bool, hasData: bool}>> $rows */
        $rows = array_chunk($cells, $columns);

        return $rows;
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, null, null, [], false);
    }
}
