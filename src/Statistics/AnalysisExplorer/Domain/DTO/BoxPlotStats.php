<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\HospitalPopulation\Application\DTO\DescriptiveStats;

final readonly class BoxPlotStats
{
    public function __construct(
        public int $count,
        public ?float $minimum,
        public ?float $p25,
        public ?float $median,
        public ?float $p75,
        public ?float $maximum,
    ) {
    }

    public static function fromDescriptiveStats(DescriptiveStats $stats): self
    {
        return new self(
            count: $stats->count,
            minimum: null !== $stats->minimum ? (float) $stats->minimum : null,
            p25: $stats->p25,
            median: $stats->median,
            p75: $stats->p75,
            maximum: null !== $stats->maximum ? (float) $stats->maximum : null,
        );
    }

    /**
     * @return list<float>
     */
    public function apexValues(): array
    {
        return [
            $this->minimum ?? 0.0,
            $this->p25 ?? 0.0,
            $this->median ?? 0.0,
            $this->p75 ?? 0.0,
            $this->maximum ?? 0.0,
        ];
    }
}
