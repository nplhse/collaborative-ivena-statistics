<?php

declare(strict_types=1);

namespace App\Feedback\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scores a feedback submission and decides whether it is accepted, quarantined or rejected.
 *
 * Only clearly invalid submissions are rejected (honeypot filled, message over the hard limit). Everything
 * else is scored; at the threshold the entry goes into quarantine. Weights are chosen so that a single weak
 * signal (a fast submission, one link, a missing page context, a repeated email) stays below the threshold;
 * only configured hard rules (blocked link domain, spam pattern) reach it on their own.
 */
final readonly class FeedbackSpamChecker
{
    public const int WEIGHT_SUBMITTED_TOO_FAST = 4;
    public const int WEIGHT_MISSING_RENDER_TIMESTAMP = 1;
    public const int WEIGHT_MISSING_PAGE_CONTEXT = 4;
    public const int WEIGHT_CONTAINS_URL = 4;
    public const int WEIGHT_MULTIPLE_URLS = 2;
    public const int WEIGHT_CONTAINS_HTML = 3;
    public const int WEIGHT_KEYWORDS_MAX = 3;
    public const int WEIGHT_VERY_LONG_MESSAGE = 2;
    public const int WEIGHT_REPEATED_GUEST_EMAIL = 3;
    public const int WEIGHT_DUPLICATE_MESSAGE = 3;
    public const int WEIGHT_TOKEN_ONLY_MESSAGE = 3;
    public const int WEIGHT_BLOCKED_LINK_DOMAIN = 6;
    public const int WEIGHT_SPAM_PATTERN = 6;

    private const string URL_PATTERN = '/\b(?:https?:\/\/|www\.)\S+/iu';

    /**
     * @param list<string> $keywords
     * @param list<string> $blockedLinkDomains
     * @param list<string> $patterns           PCRE patterns with delimiters
     */
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI container. */
    public function __construct(
        #[Autowire(param: 'app.feedback.spam.min_submission_seconds')]
        private int $minSubmissionSeconds,
        #[Autowire(param: 'app.feedback.spam.long_message_threshold')]
        private int $longMessageThreshold,
        #[Autowire(param: 'app.feedback.spam.max_message_length')]
        private int $maxMessageLength,
        #[Autowire(param: 'app.feedback.spam.anonymous_threshold')]
        private int $anonymousThreshold,
        #[Autowire(param: 'app.feedback.spam.authenticated_threshold')]
        private int $authenticatedThreshold,
        #[Autowire(param: 'app.feedback.spam.authenticated_score_bonus')]
        private int $authenticatedScoreBonus,
        /** @var list<string> */
        #[Autowire(param: 'app.feedback.spam.keywords')]
        private array $keywords,
        /** @var list<string> */
        #[Autowire(param: 'app.feedback.spam.blocked_link_domains')]
        private array $blockedLinkDomains = [],
        /** @var list<string> */
        #[Autowire(param: 'app.feedback.spam.patterns')]
        private array $patterns = [],
    ) {
    }

    /**
     * @param bool $hasPageContext whether the submission names the page it was sent from (only scored for guests)
     */
    public function check(
        string $message,
        ?string $honeypotValue,
        ?int $renderedAtTimestamp,
        int $submittedAtTimestamp,
        bool $isAuthenticated,
        bool $hasPageContext = true,
        FeedbackSpamHistory $history = new FeedbackSpamHistory(),
    ): FeedbackSpamCheckResult {
        if (null !== $honeypotValue && '' !== trim($honeypotValue)) {
            return new FeedbackSpamCheckResult(FeedbackSpamVerdict::REJECT, 100, ['honeypot_filled']);
        }

        if (mb_strlen($message) > $this->maxMessageLength) {
            return new FeedbackSpamCheckResult(FeedbackSpamVerdict::REJECT, 100, ['message_too_long']);
        }

        $score = 0;
        $reasons = [];
        $add = static function (int $weight, string $reason) use (&$score, &$reasons): void {
            $score += $weight;
            $reasons[] = $reason;
        };

        if (null !== $renderedAtTimestamp && $renderedAtTimestamp > 0) {
            if ($submittedAtTimestamp - $renderedAtTimestamp < $this->minSubmissionSeconds) {
                $add(self::WEIGHT_SUBMITTED_TOO_FAST, 'submitted_too_fast');
            }
        } elseif (!$isAuthenticated) {
            $add(self::WEIGHT_MISSING_RENDER_TIMESTAMP, 'missing_render_timestamp');
        }

        if (!$hasPageContext && !$isAuthenticated) {
            $add(self::WEIGHT_MISSING_PAGE_CONTEXT, 'missing_page_context');
        }

        $urls = [];
        preg_match_all(self::URL_PATTERN, $message, $urls);
        $urls = $urls[0];
        if ([] !== $urls) {
            $add(self::WEIGHT_CONTAINS_URL, 'contains_url');
            if (\count($urls) >= 2) {
                $add(self::WEIGHT_MULTIPLE_URLS, 'multiple_urls');
            }

            if ($this->containsBlockedLinkDomain($urls)) {
                $add(self::WEIGHT_BLOCKED_LINK_DOMAIN, 'blocked_link_domain');
            }
        }

        if (1 === preg_match('/<[^>]+>/', $message)) {
            $add(self::WEIGHT_CONTAINS_HTML, 'contains_html');
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
            $add(min(self::WEIGHT_KEYWORDS_MAX, $keywordMatches), 'spam_keywords');
        }

        foreach ($this->patterns as $pattern) {
            if ('' !== $pattern && 1 === preg_match($pattern, $message)) {
                $add(self::WEIGHT_SPAM_PATTERN, 'spam_pattern');
                break;
            }
        }

        if (1 === preg_match('/^(?=\S*\p{L})(?=\S*\p{N})[\p{L}\p{N}]{12,}$/u', trim($message))) {
            $add(self::WEIGHT_TOKEN_ONLY_MESSAGE, 'token_only_message');
        }

        if (mb_strlen($message) > $this->longMessageThreshold) {
            $add(self::WEIGHT_VERY_LONG_MESSAGE, 'very_long_message');
        }

        if ($history->earlierEntriesFromGuestEmail > 0) {
            $add(self::WEIGHT_REPEATED_GUEST_EMAIL, 'repeated_guest_email');
        }

        if ($history->earlierEntriesWithSameMessage > 0) {
            $add(self::WEIGHT_DUPLICATE_MESSAGE, 'duplicate_message');
        }

        if ($isAuthenticated) {
            $score = max(0, $score - $this->authenticatedScoreBonus);
        }

        $threshold = $isAuthenticated ? $this->authenticatedThreshold : $this->anonymousThreshold;
        $verdict = $score >= $threshold ? FeedbackSpamVerdict::QUARANTINE : FeedbackSpamVerdict::ACCEPT;

        return new FeedbackSpamCheckResult($verdict, $score, $reasons);
    }

    /** @param list<string> $urls */
    private function containsBlockedLinkDomain(array $urls): bool
    {
        foreach ($urls as $url) {
            $host = parse_url(str_starts_with(strtolower($url), 'www.') ? 'http://'.$url : $url, \PHP_URL_HOST);
            if (!\is_string($host) || '' === $host) {
                continue;
            }

            $host = strtolower(rtrim($host, '.'));
            foreach ($this->blockedLinkDomains as $domain) {
                $domain = strtolower(trim($domain, " .\t\n\r"));
                if ('' !== $domain && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                    return true;
                }
            }
        }

        return false;
    }
}
