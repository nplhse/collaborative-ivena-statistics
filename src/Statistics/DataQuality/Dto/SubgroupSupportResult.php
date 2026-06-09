<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class SubgroupSupportResult
{
    /**
     * @param list<SubgroupCell> $cells
     * @param list<SubgroupCell> $weaklySupportedCells
     */
    public function __construct(
        public DataQualityLevel $level,
        public int $totalCellsChecked,
        public int $relevantCellsCount,
        public int $supportedCellsCount,
        public float $supportedPopulationShare,
        public float $supportedRelevantCellShare,
        public array $cells,
        public array $weaklySupportedCells,
    ) {
    }
}
