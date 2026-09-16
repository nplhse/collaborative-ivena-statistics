<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

/**
 * Offset page of dimension values with the same method names as Shared\Paginator for Twig macros.
 */
final readonly class InsightDirectoryPage
{
    /**
     * @param list<InsightValueRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalAllocations,
        private int $currentPage,
        private int $pageSize,
        private int $numResults,
    ) {
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getLastPage(): int
    {
        if ($this->pageSize < 1) {
            return 1;
        }

        return max(1, (int) ceil($this->numResults / $this->pageSize));
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getLastPage();
    }

    public function getNextPage(): int
    {
        return min($this->getLastPage(), $this->currentPage + 1);
    }

    public function hasToPaginate(): bool
    {
        return $this->numResults > $this->pageSize;
    }

    public function getNumResults(): int
    {
        return $this->numResults;
    }
}
