<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use Symfony\UX\Pagination\NumberedPaginationInterface;

final readonly class InsightDirectoryPage
{
    /**
     * @param list<InsightValueRow>              $rows
     * @param NumberedPaginationInterface<mixed> $pagination
     */
    public function __construct(
        public array $rows,
        public int $totalAllocations,
        public NumberedPaginationInterface $pagination,
    ) {
    }
}
