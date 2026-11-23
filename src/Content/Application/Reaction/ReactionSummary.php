<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

use App\Content\Domain\Enum\ReactionType;

/**
 * The reactions of one target as the reaction bar shows them: one count per given reaction, in the order of
 * {@see ReactionType}.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the reaction bar template.
 */
final readonly class ReactionSummary
{
    /**
     * @param list<ReactionCount>       $counts       only reactions given at least once
     * @param list<ReactionParticipant> $participants the latest people who reacted, newest first; empty when names are
     *                                                not shown
     */
    public function __construct(
        public ReactionTarget $target,
        public array $counts = [],
        public array $participants = [],
    ) {
    }

    /**
     * Everyone who reacted: a user has at most one reaction per target.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the reaction bar template.
     */
    public function participantCount(): int
    {
        return array_sum(array_map(static fn (ReactionCount $count): int => $count->count, $this->counts));
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the reaction bar template. */
    public function own(): ?ReactionType
    {
        foreach ($this->counts as $count) {
            if ($count->own) {
                return $count->type;
            }
        }

        return null;
    }
}
