<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListArrayPaginator;

final readonly class TopListTableLimitFooter
{
    /**
     * @param array<int, string> $urls
     */
    public function __construct(
        public array $urls,
        public int $current,
        public ?TopListArrayPaginator $paginator = null,
        public bool $truncated = false,
    ) {
    }

    /**
     * @return array{urls: array<int, string>, current: int, paginator: ?TopListArrayPaginator, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'urls' => $this->urls,
            'current' => $this->current,
            'paginator' => $this->paginator,
            'truncated' => $this->truncated,
        ];
    }
}
