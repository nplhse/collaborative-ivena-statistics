<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\RankingTable;

final readonly class RankingTableAction
{
    public function __construct(
        public string $href,
        public string $icon,
        public string $labelKey,
        public string $testId,
    ) {
    }
}
