<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

/**
 * Leitstelle as a geographic system: inflow, local stays, and outflow.
 *
 * Inflow: origin outside, hospital inside.
 * Local: origin and hospital inside.
 * Outflow: origin inside, hospital outside.
 * Total = inflow + local + outflow.
 */
final readonly class CaseFlowDispatchAreaFlow
{
    public function __construct(
        public int $inflowCases,
        public int $localCases,
        public int $outflowCases,
        public int $totalCases,
        public ?float $inflowSharePercent,
        public ?float $localSharePercent,
        public ?float $outflowSharePercent,
        public ?float $insideDestinationSharePercent,
        public ?float $localOriginSharePercent,
        public bool $showDestinationSplit = true,
    ) {
    }

    public function insideDestinationCases(): int
    {
        return $this->inflowCases + $this->localCases;
    }

    public function localOriginCases(): int
    {
        return $this->localCases + $this->outflowCases;
    }
}
