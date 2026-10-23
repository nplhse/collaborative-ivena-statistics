<?php

declare(strict_types=1);

namespace App\Import\Application\Event;

/**
 * Dispatched after commit when a run recorded a new user import milestone.
 */
final readonly class ImportMilestoneReached
{
    public function __construct(
        public int $milestoneId,
    ) {
    }
}
