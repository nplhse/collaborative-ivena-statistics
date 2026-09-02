<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListLimit;

final readonly class TopListsRequestModel
{
    public function __construct(
        public string $topListKey,
        public TopListLimit $limit,
        public int $page = 1,
        public bool $compare = false,
    ) {
    }
}
