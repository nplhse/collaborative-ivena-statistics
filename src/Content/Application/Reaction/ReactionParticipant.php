<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

/**
 * Someone who reacted to a target, as the avatar stack of the reaction bar shows them.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the reaction bar template.
 */
final readonly class ReactionParticipant
{
    public function __construct(
        public int $userId,
        public string $username,
        public ?string $displayName,
    ) {
    }
}
