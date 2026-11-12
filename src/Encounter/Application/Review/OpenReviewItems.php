<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;

/**
 * Rows of the "open" segment (#1054): one per allocation that needs a person, i.e. an open proposal (also
 * in conflict) or a swap proposal, filtered by kind and sorted as the context asks. The period holds a few
 * hundred allocations at most, so filtering and sorting happen here.
 */
final readonly class OpenReviewItems
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MatchReviewReaderInterface $review,
        private MatchSwapFinderInterface $swaps,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $hospitalId, ReviewContext $context): array
    {
        $period = $context->period;
        $swapAllocations = array_map(
            static fn (SwapProposal $proposal): int => $proposal->allocationId,
            $this->swaps->between($hospitalId, $period->from, $period->toExclusive()),
        );
        $items = $this->review->openItems($hospitalId, $period, $swapAllocations);

        $items = array_values(array_filter($items, static fn (array $item): bool => match ($context->kind) {
            'conflicts' => true === $item['open_conflict'],
            'swaps' => true === $item['swap'],
            default => true,
        }));

        $key = static fn (array $item): array => match ($context->sortBy) {
            'agreement' => [$item['score'], $item['arrival_at'], $item['allocation_id']],
            'difference' => [$item['time_difference_minutes'], $item['arrival_at'], $item['allocation_id']],
            default => [$item['arrival_at'], $item['allocation_id']],
        };
        usort($items, static fn (array $a, array $b): int => 'desc' === $context->orderBy ? $key($b) <=> $key($a) : $key($a) <=> $key($b));

        return $items;
    }
}
