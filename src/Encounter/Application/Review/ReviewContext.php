<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Domain\Enum\MatchStatus;

/**
 * Where a reviewer is in the match review (#1054): segment, period, the segment's filter and the sort order.
 * It travels with every link and form so that "skip" and decisions continue in the same list.
 *
 * Filters: "open" by kind (`conflicts`, `swaps`), "done" by status (`auto_accepted`, `confirmed`,
 * `rejected`). Sorting by agreement and time difference applies to "open" and "done".
 */
final readonly class ReviewContext
{
    public const array SORT_KEYS = ['arrival', 'agreement', 'difference'];

    public const array KINDS = ['conflicts', 'swaps'];

    public ?string $kind;

    public ?MatchStatus $status;

    public string $sortBy;

    public string $orderBy;

    public function __construct(
        public ReviewFilter $filter,
        public ReviewPeriod $period,
        ?string $kind = null,
        ?MatchStatus $status = null,
        string $sortBy = 'arrival',
        string $orderBy = 'asc',
    ) {
        $this->kind = ReviewFilter::Open === $filter && \in_array($kind, self::KINDS, true) ? $kind : null;
        $this->status = ReviewFilter::Done === $filter && $status instanceof MatchStatus && MatchStatus::Proposed !== $status ? $status : null;
        $this->sortBy = $this->isSortable() && \in_array($sortBy, self::SORT_KEYS, true) ? $sortBy : 'arrival';
        $this->orderBy = 'desc' === $orderBy ? 'desc' : 'asc';
    }

    public function withFilter(ReviewFilter $filter): self
    {
        return new self($filter, $this->period);
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by the review templates. */
    public function isSortable(): bool
    {
        return ReviewFilter::NoProposal !== $this->filter;
    }

    /**
     * Number of active segment filters, for the filter drawer button.
     *
     * @psalm-suppress PossiblyUnusedMethod Used by the review templates.
     */
    public function activeFilterCount(): int
    {
        return (null === $this->kind ? 0 : 1) + ($this->status instanceof MatchStatus ? 1 : 0);
    }

    /**
     * Query parameters of this context; defaults are left out.
     *
     * @psalm-suppress PossiblyUnusedMethod Used by the review templates.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            ...$this->period->query(),
            'segment' => $this->filter->value,
            'kind' => $this->kind,
            'status' => $this->status?->value,
            'sortBy' => 'arrival' === $this->sortBy ? null : $this->sortBy,
            'orderBy' => 'asc' === $this->orderBy ? null : $this->orderBy,
        ], static fn (?string $value): bool => null !== $value);
    }
}
