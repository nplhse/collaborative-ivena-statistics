<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class SummarizedReportsIndexViewModel
{
    /**
     * @param list<SummarizedReportsIndexCardViewModel> $cards
     */
    public function __construct(
        public array $cards,
    ) {
    }
}
