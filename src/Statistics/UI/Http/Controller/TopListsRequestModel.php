<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class TopListsRequestModel
{
    public function __construct(
        public string $topListKey,
        public int $limit,
    ) {
    }
}
