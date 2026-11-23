<?php

declare(strict_types=1);

namespace App\Content\Application\Message;

use App\Content\Domain\Enum\ReactionTargetType;

/** Recomputes the grouped notification of one reaction target (#973). Safe to run any number of times. */
final readonly class RefreshReactionNotificationMessage
{
    public function __construct(
        public ReactionTargetType $targetType,
        public int $targetId,
    ) {
    }
}
