<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

final readonly class CursorPaginator
{
    /**
     * @param list<array<string, mixed>> $results
     */
    public function __construct(
        private array $results,
        private int $pageSize,
        private ?string $nextCursor,
        private ?string $previousCursor = null,
        private ?int $estimatedNumResults = null,
    ) {
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function hasNextPage(): bool
    {
        return null !== $this->nextCursor;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function hasPreviousPage(): bool
    {
        return null !== $this->previousCursor;
    }

    public function getPreviousCursor(): ?string
    {
        return $this->previousCursor;
    }

    public function hasToPaginate(): bool
    {
        return $this->hasNextPage() || $this->hasPreviousPage();
    }

    public function getEstimatedNumResults(): ?int
    {
        return $this->estimatedNumResults;
    }

    public function getEstimatedLastPage(): ?int
    {
        if (null === $this->estimatedNumResults || 0 >= $this->estimatedNumResults) {
            return null;
        }

        return (int) ceil($this->estimatedNumResults / $this->pageSize);
    }

    /**
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getResults(): \Traversable
    {
        yield from $this->results;
    }
}
