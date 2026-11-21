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
     * @param list<ReactionCount> $counts only reactions given at least once
     */
    public function __construct(
        public ReactionTarget $target,
        public array $counts = [],
    ) {
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
