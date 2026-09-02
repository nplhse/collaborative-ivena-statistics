<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListArrayPaginator;

final readonly class TopListTableLimitFooter
{
    /**
     * @param array<int|string, string> $urls
     */
    public function __construct(
        public array $urls,
        public int|string $current,
        public ?TopListArrayPaginator $paginator = null,
        public bool $truncated = false,
    ) {
    }

    /**
     * @return array{urls: array<int|string, string>, current: int|string, paginator: ?TopListArrayPaginator, truncated: bool}
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
