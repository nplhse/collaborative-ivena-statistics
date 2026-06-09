<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Statistics\HospitalPopulation\Application\DTO\CoverageRow;
use App\Statistics\HospitalPopulation\Application\DTO\RepresentativityRow;

final readonly class RepresentativityCalculator
{
    public function __construct(
        private CoverageCalculator $coverageCalculator,
    ) {
    }

    /**
     * @param list<CoverageRow> $coverageRows
     *
     * @return list<RepresentativityRow>
     */
    public function fromCoverageRows(
        array $coverageRows,
        int $totalPopulation,
        int $totalParticipants,
    ): array {
        return array_map(
            fn (CoverageRow $row): RepresentativityRow => new RepresentativityRow(
                key: $row->key,
                label: $row->label,
                populationCount: $row->population,
                participantCount: $row->participants,
                populationSharePercent: $this->coverageCalculator->calculatePercent($row->population, $totalPopulation),
                participantSharePercent: $this->coverageCalculator->calculatePercent($row->participants, $totalParticipants),
                deltaPercentPoints: $this->coverageCalculator->calculatePercent($row->participants, $totalParticipants)
                    - $this->coverageCalculator->calculatePercent($row->population, $totalPopulation),
            ),
            $coverageRows,
        );
    }
}
