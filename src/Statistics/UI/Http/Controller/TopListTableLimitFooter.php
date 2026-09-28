<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\UX\Pagination\NumberedPaginationInterface;

final readonly class TopListTableLimitFooter
{
    /**
     * @param array<int, string>                      $urls
     * @param NumberedPaginationInterface<mixed>|null $paginator
     */
    public function __construct(
        public array $urls,
        public int $current,
        public ?NumberedPaginationInterface $paginator = null,
        public bool $truncated = false,
    ) {
    }

    /**
     * @return array{urls: array<int, string>, current: int, paginator: NumberedPaginationInterface<mixed>|null, truncated: bool}
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
