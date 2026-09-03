<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListArrayPaginator
{
    public function __construct(
        private int $currentPage,
        private int $pageSize,
        private int $numResults,
    ) {
    }

    public static function fromCount(int $numResults, int $page, int $pageSize): self
    {
        $lastPage = max(1, (int) ceil(max(0, $numResults) / max(1, $pageSize)));
        $currentPage = min(max(1, $page), $lastPage);

        return new self($currentPage, max(1, $pageSize), max(0, $numResults));
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getLastPage(): int
    {
        if (0 === $this->numResults) {
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
