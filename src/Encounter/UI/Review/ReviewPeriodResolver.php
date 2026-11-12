<?php

declare(strict_types=1);

namespace App\Encounter\UI\Review;

use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Review\ReviewPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Period of a review page from `from`/`to` (Y-m-d); by default the calendar month of the hospital's latest
 * visit, or the current month without visits.
 */
final readonly class ReviewPeriodResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewReaderInterface $review,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(Request $request, int $hospitalId): ReviewPeriod
    {
        $fallback = ReviewPeriod::monthOf($this->review->latestVisitDay($hospitalId) ?? $this->clock->now());

        return ReviewPeriod::fromStrings(
            $request->query->getString('from') ?: null,
            $request->query->getString('to') ?: null,
            $fallback,
        );
    }
}
