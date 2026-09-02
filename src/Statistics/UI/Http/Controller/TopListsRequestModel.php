<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListPageSizePolicy;

final readonly class TopListsRequestModel
{
    public function __construct(
        public string $topListKey,
        public TopListLimit $limit,
        public int $page = 1,
        public bool $compare = false,
        public int $pageSize = TopListPageSizePolicy::DEFAULT,
    ) {
    }
}
