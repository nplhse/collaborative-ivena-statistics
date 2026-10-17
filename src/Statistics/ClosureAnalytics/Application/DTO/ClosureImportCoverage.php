<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Statistics\ClosureAnalytics\Application\Volume\ClosureCoverage;

/**
 * Coverage of one hospital by its completed imports of one type, from the derived or manual export periods.
 * Imports without a period are counted separately; while one exists, gaps may be overstated.
 */
final readonly class ClosureImportCoverage
{
    public const string ALLOCATIONS = 'allocations';
    public const string CLOSURES = 'closures';

    public function __construct(
        public string $kind,
        public int $imports,
        public int $withoutPeriod,
        public ClosureCoverage $coverage,
    ) {
    }

    public function hasImports(): bool
    {
        return $this->imports > 0;
    }

    public function firstDay(): ?\DateTimeImmutable
    {
        $ranges = $this->coverage->ranges();

        return [] === $ranges ? null : $ranges[0][0];
    }

    public function lastDay(): ?\DateTimeImmutable
    {
        $ranges = $this->coverage->ranges();

        return [] === $ranges ? null : $ranges[\count($ranges) - 1][1]->modify('-1 second')->setTime(0, 0);
    }

    /**
     * @return list<ClosureImportCoverageGap>
     */
    public function gaps(): array
    {
        return array_map(
            static fn (array $gap): ClosureImportCoverageGap => new ClosureImportCoverageGap($gap[0], $gap[1]),
            $this->coverage->gaps(),
        );
    }
}
