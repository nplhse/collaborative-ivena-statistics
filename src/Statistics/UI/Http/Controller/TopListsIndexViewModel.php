<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class TopListsIndexViewModel
{
    /**
     * @param list<TopListsIndexCardViewModel> $cards
     */
    public function __construct(
        public array $cards,
    ) {
    }
}
