<?php

declare(strict_types=1);

namespace App\Feedback\Application;

final readonly class FeedbackSpamCheckResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        private bool $spam,
        private int $score,
        private array $reasons = [],
    ) {
    }

    public function isSpam(): bool
    {
        return $this->spam;
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
