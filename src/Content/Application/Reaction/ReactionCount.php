<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

use App\Content\Domain\Enum\ReactionType;

/**
 * How often one reaction was given to a target, with the first names for the tooltip.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the reaction bar template.
 */
final readonly class ReactionCount
{
    /**
     * @param list<string> $names display names of the first users, oldest first; empty when names are not shown
     */
    public function __construct(
        public ReactionType $type,
        public int $count,
        public bool $own,
        public array $names = [],
    ) {
    }

    /**
     * Users counted but not named in the tooltip.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the reaction bar template.
     */
    public function unnamed(): int
    {
        return [] === $this->names ? 0 : max(0, $this->count - \count($this->names));
    }
}
