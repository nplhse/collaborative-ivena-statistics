<?php

declare(strict_types=1);

namespace App\Encounter\UI\Review;

use App\Encounter\Application\Review\MatchReviewNavigator;
use App\Encounter\Application\Review\ReviewContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the review continues after "skip" or a decision: the next allocation of the segment, or the
 * segment's list when none is left (#1054).
 */
final readonly class ReviewNextUrl
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewNavigator $navigator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{url: string, done: bool} `done` when the segment has no further allocation
     */
    public function after(int $hospitalId, ReviewContext $context, int $allocationId, ?int $position): array
    {
        $next = $this->navigator->next($hospitalId, $context, $allocationId, $position);

        return null === $next ? ['url' => $this->list($hospitalId, $context), 'done' => true] : ['url' => $this->allocation($hospitalId, $context, $next), 'done' => false];
    }

    public function allocation(int $hospitalId, ReviewContext $context, int $allocationId): string
    {
        return $this->urls->generate('app_encounter_review_allocation', ['id' => $hospitalId, 'allocationId' => $allocationId, ...$context->query()]);
    }

    public function list(int $hospitalId, ReviewContext $context): string
    {
        return $this->urls->generate('app_encounter_review_hospital', ['id' => $hospitalId, ...$context->query()]);
    }
}
