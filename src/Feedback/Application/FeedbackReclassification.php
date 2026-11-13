<?php

declare(strict_types=1);

namespace App\Feedback\Application;

/**
 * Result of re-checking one existing normal feedback entry (issue #964): ids, time and reason codes only.
 */
final readonly class FeedbackReclassification
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public int $feedbackId,
        public \DateTimeImmutable $createdAt,
        public int $score,
        public array $reasons,
    ) {
    }
}
