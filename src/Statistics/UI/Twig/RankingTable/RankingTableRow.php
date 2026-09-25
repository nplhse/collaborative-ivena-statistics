<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\RankingTable;

final readonly class RankingTableRow
{
    public function __construct(
        public string $rank,
        public string $label,
        public string $count,
        public string $share,
        public ?string $labelHref = null,
        public ?string $labelContext = null,
        public ?RankingTableRankShift $rankShift = null,
        public int|float|null $countDelta = null,
        public int|float|null $shareDelta = null,
        public ?float $shareBar = null,
        public ?RankingTableAction $action = null,
        public ?string $testId = null,
    ) {
    }
}
