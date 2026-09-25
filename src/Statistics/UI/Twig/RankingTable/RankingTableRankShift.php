<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\RankingTable;

final readonly class RankingTableRankShift
{
    public function __construct(
        public bool $entered = false,
        public ?int $rankDelta = null,
        public string $newLabelKey = 'stats.top_lists.comparison.new',
        public string $ariaNewKey = 'stats.top_lists.comparison.rank.aria.new',
        public string $ariaUpKey = 'stats.top_lists.comparison.rank.aria.up',
        public string $ariaDownKey = 'stats.top_lists.comparison.rank.aria.down',
        public string $testId = 'stats-top-lists-rank-badge',
    ) {
    }

    public function cellClass(): string
    {
        if ($this->entered) {
            return 'stats-rank-shift-new';
        }

        if (null !== $this->rankDelta && $this->rankDelta > 0) {
            return 'stats-rank-shift-up';
        }

        if (null !== $this->rankDelta && $this->rankDelta < 0) {
            return 'stats-rank-shift-down';
        }

        return '';
    }
}
