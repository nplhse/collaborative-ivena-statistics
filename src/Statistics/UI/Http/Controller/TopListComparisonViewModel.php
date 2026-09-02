<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListComparisonRow;

final readonly class TopListComparisonViewModel
{
    /**
     * @param list<TopListComparisonRow> $rowsA
     * @param list<TopListComparisonRow> $rowsB
     */
    public function __construct(
        public array $rowsA,
        public array $rowsB,
        public int $totalAllocationsA,
        public int $totalAllocationsB,
        public string $sideAHeading,
        public string $sideASubheading,
        public string $sideBHeading,
        public string $sideBSubheading,
        public string $labelColumnTranslationKey,
        public bool $linkDiagnoses,
    ) {
    }
}
