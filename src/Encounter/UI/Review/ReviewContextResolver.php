<?php

declare(strict_types=1);

namespace App\Encounter\UI\Review;

use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Review\ReviewContext;
use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Enum\MatchStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Review context from the query string: `segment`, `from`/`to` (Y-m-d), `kind`, `status`, `sortBy`,
 * `orderBy`. The period defaults to the calendar month of the hospital's latest visit, or the current month
 * without visits; the segment to "open".
 */
final readonly class ReviewContextResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewReaderInterface $review,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(Request $request, int $hospitalId): ReviewContext
    {
        return new ReviewContext(
            ReviewFilter::tryFrom($request->query->getString('segment')) ?? ReviewFilter::Open,
            $this->period($request, $hospitalId),
            $request->query->getString('kind') ?: null,
            MatchStatus::tryFrom($request->query->getString('status')),
            $request->query->getString('sortBy', 'arrival'),
            $request->query->getString('orderBy', 'asc'),
        );
    }

    public function period(Request $request, int $hospitalId): ReviewPeriod
    {
        $fallback = ReviewPeriod::monthOf($this->review->latestVisitDay($hospitalId) ?? $this->clock->now());

        return ReviewPeriod::fromStrings($request->query->getString('from') ?: null, $request->query->getString('to') ?: null, $fallback);
    }
}
