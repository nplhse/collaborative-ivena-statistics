<?php

declare(strict_types=1);

namespace App\Feedback\Application\Event;

/**
 * Dispatched after a feedback entry has been stored.
 */
final readonly class FeedbackReceived
{
    public function __construct(
        public int $feedbackId,
    ) {
    }
}
