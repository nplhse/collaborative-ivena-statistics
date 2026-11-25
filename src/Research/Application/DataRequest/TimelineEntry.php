<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Entity\DataRequestEvent;

/**
 * One entry of a data extract request's timeline: an event or a top-level comment with its replies.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class TimelineEntry
{
    public function __construct(
        public \DateTimeImmutable $at,
        public ?DataRequestEvent $event = null,
        public ?DataRequestComment $comment = null,
    ) {
    }
}
