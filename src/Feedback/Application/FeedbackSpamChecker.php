<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FeedbackSpamChecker
{
    /**
     * @param list<string> $keywords
     */
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        #[Autowire('%app.feedback.spam.min_submission_seconds%')]
        private int $minSubmissionSeconds,
        #[Autowire('%app.feedback.spam.long_message_threshold%')]
        private int $longMessageThreshold,
        #[Autowire('%app.feedback.spam.anonymous_threshold%')]
        private int $anonymousThreshold,
        #[Autowire('%app.feedback.spam.authenticated_threshold%')]
        private int $authenticatedThreshold,
        #[Autowire('%app.feedback.spam.authenticated_score_bonus%')]
        private int $authenticatedScoreBonus,
        /** @var list<string> */
        #[Autowire('%app.feedback.spam.keywords%')]
        private array $keywords,
    ) {
    }

    public function check(
        string $message,
        ?string $honeypotValue,
        ?int $renderedAtTimestamp,
        int $submittedAtTimestamp,
        bool $isAuthenticated,
    ): FeedbackSpamCheckResult {
        $score = 0;
        $reasons = [];

        if (null !== $honeypotValue && '' !== trim($honeypotValue)) {
            return new FeedbackSpamCheckResult(true, 100, ['honeypot_filled']);
        }

        if (null !== $renderedAtTimestamp && $renderedAtTimestamp > 0) {
            $elapsed = $submittedAtTimestamp - $renderedAtTimestamp;
            if ($elapsed < $this->minSubmissionSeconds) {
                $score += 6;
                $reasons[] = 'submitted_too_fast';
            }
        } elseif (!$isAuthenticated) {
            ++$score;
            $reasons[] = 'missing_render_timestamp';
        }

        $urlCount = preg_match_all('/\b(?:https?:\/\/|www\.)\S+/i', $message);
        if (\is_int($urlCount) && $urlCount > 1) {
            $score += 3;
            $reasons[] = 'multiple_urls';
        }

        if (1 === preg_match('/<[^>]+>/', $message)) {
            $score += 3;
            $reasons[] = 'contains_html';
        }

        $keywordMatches = 0;
        foreach ($this->keywords as $keyword) {
            if ('' === trim($keyword)) {
                continue;
            }

            if (1 === preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $message)) {
                ++$keywordMatches;
            }
        }

        if ($keywordMatches > 0) {
            $score += min(3, $keywordMatches);
            $reasons[] = 'spam_keywords';
        }

        if (mb_strlen($message) > $this->longMessageThreshold) {
            $score += 2;
            $reasons[] = 'very_long_message';
        }

        if ($isAuthenticated) {
            $score = max(0, $score - $this->authenticatedScoreBonus);
        }

        $threshold = $isAuthenticated ? $this->authenticatedThreshold : $this->anonymousThreshold;

        return new FeedbackSpamCheckResult($score >= $threshold, $score, $reasons);
    }
}
