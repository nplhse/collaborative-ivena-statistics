<?php

declare(strict_types=1);

namespace App\Content\Application\Event;

use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;

/**
 * A user set, replaced or removed their reaction to a target (#467). Grouped reaction notifications (#973) can
 * listen to it; nothing does yet.
 *
 * @psalm-suppress PossiblyUnusedProperty Public event data for later listeners (#973).
 */
final readonly class ReactionChanged
{
    public function __construct(
        public ReactionTargetType $targetType,
        public int $targetId,
        public int $userId,
        public ?ReactionType $previous,
        public ?ReactionType $current,
    ) {
    }
}
