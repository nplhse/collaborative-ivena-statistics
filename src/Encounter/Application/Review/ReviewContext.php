<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Domain\Enum\ArrivalRoute;

/**
 * Where a reviewer is in the match review (#1054): segment, period, arrival route filter and sort order.
 * It travels with every link and form so that "skip" and decisions continue in the same list.
 */
final readonly class ReviewContext
{
    /** Public sort keys; pair segments only, every other list keeps its natural order. */
    public const array SORT_KEYS = ['arrival', 'agreement', 'difference'];

    public string $sortBy;

    public string $orderBy;

    public function __construct(
        public ReviewFilter $filter,
        public ReviewPeriod $period,
        public ?ArrivalRoute $route = null,
        string $sortBy = 'arrival',
        string $orderBy = 'asc',
    ) {
        $this->sortBy = $filter->listsPairs() && \in_array($sortBy, self::SORT_KEYS, true) ? $sortBy : 'arrival';
        $this->orderBy = 'desc' === $orderBy ? 'desc' : 'asc';
    }

    public function withFilter(ReviewFilter $filter): self
    {
        return new self($filter, $this->period, ReviewFilter::UnlinkedVisits === $filter ? $this->route : null);
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
            'route' => $this->route?->value,
            'sortBy' => 'arrival' === $this->sortBy ? null : $this->sortBy,
            'orderBy' => 'asc' === $this->orderBy ? null : $this->orderBy,
        ], static fn (?string $value): bool => null !== $value);
    }
}
