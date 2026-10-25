<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

use Symfony\UX\Pagination\CursorPaginationInterface;

/**
 * One keyset page of rows, built by a hand-written query.
 *
 * Implements the Symfony UX cursor contract so DataTable treats it like any
 * other cursor pagination; adds an estimated total that UX cursors do not have.
 * The rows themselves carry no URL knowledge: callers that need URLs from the
 * paginator (rather than from DataTable) attach a generator with withCursorUrlGenerator().
 *
 * @implements CursorPaginationInterface<array<string, mixed>>
 */
final readonly class CursorPaginator implements CursorPaginationInterface
{
    /**
     * @param list<array<string, mixed>>      $results
     * @param (\Closure(string): string)|null $cursorUrlGenerator
     */
    public function __construct(
        private array $results,
        private int $pageSize,
        private ?string $nextCursor,
        private ?string $previousCursor = null,
        private ?int $estimatedNumResults = null,
        private ?string $cursor = null,
        private ?\Closure $cursorUrlGenerator = null,
    ) {
    }

    /**
     * @param \Closure(string): string $generator
     */
    public function withCursorUrlGenerator(\Closure $generator): self
    {
        return new self(
            $this->results,
            $this->pageSize,
            $this->nextCursor,
            $this->previousCursor,
            $this->estimatedNumResults,
            $this->cursor,
            $generator,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getItems(): array
    {
        return $this->results;
    }

    /**
     * @return \Traversable<int, array<string, mixed>>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->results);
    }

    /**
     * Number of rows on this page, not the total.
     */
    #[\Override]
    public function count(): int
    {
        return \count($this->results);
    }

    #[\Override]
    public function isEmpty(): bool
    {
        return [] === $this->results;
    }

    #[\Override]
    public function getItemsPerPage(): int
    {
        return $this->pageSize;
    }

    #[\Override]
    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    #[\Override]
    public function hasNext(): bool
    {
        return null !== $this->nextCursor;
    }

    #[\Override]
    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    #[\Override]
    public function hasPrevious(): bool
    {
        return null !== $this->previousCursor;
    }

    #[\Override]
    public function getPreviousCursor(): ?string
    {
        return $this->previousCursor;
    }

    #[\Override]
    public function getNextUrl(): ?string
    {
        return null === $this->nextCursor ? null : $this->getCursorUrl($this->nextCursor);
    }

    #[\Override]
    public function getPreviousUrl(): ?string
    {
        return null === $this->previousCursor ? null : $this->getCursorUrl($this->previousCursor);
    }

    #[\Override]
    public function getCursorUrl(string $cursor): string
    {
        if (!$this->cursorUrlGenerator instanceof \Closure) {
            throw new \LogicException('No cursor URL generator configured. Call withCursorUrlGenerator() or let DataTable build the URLs.');
        }

        return ($this->cursorUrlGenerator)($cursor);
    }

    #[\Override]
    public function getInfo(): string
    {
        return '';
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
     * @return array{items: list<array<string, mixed>>, per_page: int, cursor: ?string, next_cursor: ?string, previous_cursor: ?string, has_next: bool, has_previous: bool, estimated_total: ?int}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->results,
            'per_page' => $this->pageSize,
            'cursor' => $this->cursor,
            'next_cursor' => $this->nextCursor,
            'previous_cursor' => $this->previousCursor,
            'has_next' => $this->hasNext(),
            'has_previous' => $this->hasPrevious(),
            'estimated_total' => $this->estimatedNumResults,
        ];
    }
}
