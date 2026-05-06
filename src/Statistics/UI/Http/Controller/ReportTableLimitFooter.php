<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class ReportTableLimitFooter
{
    /**
     * @param array<int, string> $urls
     */
    public function __construct(
        public array $urls,
        public int $current,
    ) {
    }

    /**
     * @return array{urls: array<int, string>, current: int}
     */
    public function toArray(): array
    {
        return [
            'urls' => $this->urls,
            'current' => $this->current,
        ];
    }
}
