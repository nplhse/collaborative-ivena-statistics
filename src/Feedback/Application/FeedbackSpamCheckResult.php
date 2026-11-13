<?php

declare(strict_types=1);

namespace App\Feedback\Application;

final readonly class FeedbackSpamCheckResult
{
    /**
     * @param list<string> $reasons reason codes, never message content
     */
    public function __construct(
        public FeedbackSpamVerdict $verdict,
        private int $score,
        private array $reasons = [],
    ) {
    }

    public function isRejected(): bool
    {
        return FeedbackSpamVerdict::REJECT === $this->verdict;
    }

    public function isQuarantined(): bool
    {
        return FeedbackSpamVerdict::QUARANTINE === $this->verdict;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * @return list<string>
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }
}
