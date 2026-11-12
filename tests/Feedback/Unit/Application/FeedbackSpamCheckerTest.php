<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackSpamChecker;
use App\Feedback\Application\FeedbackSpamCheckResult;
use App\Feedback\Application\FeedbackSpamHistory;
use App\Feedback\Application\FeedbackSpamVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Weights: too fast 4, missing render timestamp 1, missing page context 4, URL 4 (+2 for a second one),
 * HTML 3, keywords 1 each up to 3, blocked link domain 6, spam pattern 6, token-only message 3, very long
 * message 2, repeated guest email 3, duplicate message 3; signed-in users −2.
 * Quarantine at 6 for guests and 8 for signed-in users.
 */
final class FeedbackSpamCheckerTest extends TestCase
{
    private const int NOW = 1_762_934_400;

    private function createChecker(): FeedbackSpamChecker
    {
        return new FeedbackSpamChecker(
            minSubmissionSeconds: 4,
            longMessageThreshold: 1800,
            maxMessageLength: 4000,
            anonymousThreshold: 6,
            authenticatedThreshold: 8,
            authenticatedScoreBonus: 2,
            keywords: ['casino', 'crypto', 'loan', 'viagra', 'seo', 'backlink', 'guest post'],
            blockedLinkDomains: ['telegra.ph'],
            patterns: ['/\bearn\s+\$[\d,.]+\s+(?:per|a)\s+month\b/i'],
        );
    }

    private function check(
        string $message,
        bool $isAuthenticated = false,
        bool $hasPageContext = true,
        ?int $renderedAt = self::NOW - 20,
        string $honeypot = '',
        FeedbackSpamHistory $history = new FeedbackSpamHistory(),
    ): FeedbackSpamCheckResult {
        return $this->createChecker()->check(
            message: $message,
            honeypotValue: $honeypot,
            renderedAtTimestamp: $renderedAt,
            submittedAtTimestamp: self::NOW,
            isAuthenticated: $isAuthenticated,
            hasPageContext: $hasPageContext,
            history: $history,
        );
    }

    public function testHoneypotFilledIsRejected(): void
    {
        $result = $this->check('Legitimate looking text.', honeypot: 'https://spam.example');

        self::assertSame(FeedbackSpamVerdict::REJECT, $result->verdict);
        self::assertSame(['honeypot_filled'], $result->getReasons());
    }

    public function testMessageAboveMaxLengthIsRejected(): void
    {
        $result = $this->check(str_repeat('a', 4001));

        self::assertSame(FeedbackSpamVerdict::REJECT, $result->verdict);
        self::assertSame(100, $result->getScore());
        self::assertSame(['message_too_long'], $result->getReasons());
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('singleWeakSignals')]
    public function testASingleWeakSignalIsAccepted(string $message, bool $hasPageContext, ?int $renderedAt, FeedbackSpamHistory $history, int $score, array $reasons): void
    {
        $result = $this->check($message, hasPageContext: $hasPageContext, renderedAt: $renderedAt, history: $history);

        self::assertSame(FeedbackSpamVerdict::ACCEPT, $result->verdict);
        self::assertSame($score, $result->getScore());
        self::assertSame($reasons, $result->getReasons());
    }

    /**
     * @return iterable<string, array{string, bool, ?int, FeedbackSpamHistory, int, list<string>}>
     */
    public static function singleWeakSignals(): iterable
    {
        $none = new FeedbackSpamHistory();

        yield 'short message' => ['Ok', true, self::NOW - 20, $none, 0, []];
        yield 'fast submission' => ['Quick note', true, self::NOW - 1, $none, 4, ['submitted_too_fast']];
        yield 'one link' => ['Screenshot: https://example.test/shot.png', true, self::NOW - 20, $none, 4, ['contains_url']];
        yield 'no page context' => ['Hi, I wanted to know your price.', false, self::NOW - 20, $none, 4, ['missing_page_context']];
        yield 'no page context, no timestamp' => ['Hi, I wanted to know your price.', false, null, $none, 5, ['missing_render_timestamp', 'missing_page_context']];
        yield 'repeated guest email' => ['Follow-up to my question', true, self::NOW - 20, new FeedbackSpamHistory(earlierEntriesFromGuestEmail: 1), 3, ['repeated_guest_email']];
        yield 'same message as before' => ['The export is broken', true, self::NOW - 20, new FeedbackSpamHistory(earlierEntriesWithSameMessage: 2), 3, ['duplicate_message']];
        yield 'token-like message' => ['MEYJTJ679293MARTHHDF', true, self::NOW - 20, $none, 3, ['token_only_message']];
        yield 'two keywords' => ['crypto loan', true, self::NOW - 20, $none, 2, ['spam_keywords']];
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('quarantinedSubmissions')]
    public function testCombinedOrHardSignalsAreQuarantined(string $message, bool $hasPageContext, FeedbackSpamHistory $history, int $score, array $reasons): void
    {
        $result = $this->check($message, hasPageContext: $hasPageContext, history: $history);

        self::assertSame(FeedbackSpamVerdict::QUARANTINE, $result->verdict);
        self::assertSame($score, $result->getScore());
        self::assertSame($reasons, $result->getReasons());
    }

    /**
     * Synthetic messages modelled on the spam seen in production.
     *
     * @return iterable<string, array{string, bool, FeedbackSpamHistory, int, list<string>}>
     */
    public static function quarantinedSubmissions(): iterable
    {
        $none = new FeedbackSpamHistory();

        yield 'link to blocked domain' => [
            'Read the news and get rich https://telegra.ph/news-123',
            true, $none, 10, ['contains_url', 'blocked_link_domain'],
        ];
        yield 'blocked subdomain, no page context' => [
            'Look https://www.telegra.ph/x',
            false, $none, 14, ['missing_page_context', 'contains_url', 'blocked_link_domain'],
        ];
        yield 'spam pattern' => [
            'Read the news and earn $50,000,000 per month',
            true, $none, 6, ['spam_pattern'],
        ];
        yield 'token, no page context' => [
            'MERYTRH679293MARTHHDF',
            false, $none, 7, ['missing_page_context', 'token_only_message'],
        ];
        yield 'price question, repeated email, no page context' => [
            'Hola, quería saber tu precio.',
            false, new FeedbackSpamHistory(earlierEntriesFromGuestEmail: 1), 7, ['missing_page_context', 'repeated_guest_email'],
        ];
        yield 'same message from another address, no page context' => [
            'Hola, quería saber tu precio.',
            false, new FeedbackSpamHistory(earlierEntriesWithSameMessage: 1), 7, ['missing_page_context', 'duplicate_message'],
        ];
        yield 'two links' => [
            'See https://a.example and https://b.example for deals.',
            true, $none, 6, ['contains_url', 'multiple_urls'],
        ];
    }

    public function testLinkOnAnotherDomainEndingInTheBlockedNameIsNotBlocked(): void
    {
        $result = $this->check('See https://nottelegra.ph/page');

        self::assertSame(['contains_url'], $result->getReasons());
        self::assertSame(FeedbackSpamVerdict::ACCEPT, $result->verdict);
    }

    public function testTokenRuleNeedsLettersAndDigits(): void
    {
        self::assertNotContains('token_only_message', $this->check('Dankeschoenvielmals')->getReasons());
        self::assertNotContains('token_only_message', $this->check('123456789012345')->getReasons());
        self::assertNotContains('token_only_message', $this->check('Fehler 4711 im Export')->getReasons());
    }

    public function testSignedInUsersGetABonusAndAHigherThreshold(): void
    {
        $message = 'Visit https://a.example and https://b.example please';

        $guest = $this->check($message);
        $user = $this->check($message, isAuthenticated: true);

        self::assertSame([6, FeedbackSpamVerdict::QUARANTINE], [$guest->getScore(), $guest->verdict]);
        self::assertSame([4, FeedbackSpamVerdict::ACCEPT], [$user->getScore(), $user->verdict]);
    }

    public function testSignedInUsersAreNotScoredForMissingTimestampOrPageContext(): void
    {
        $result = $this->check('No context provided.', isAuthenticated: true, hasPageContext: false, renderedAt: null);

        self::assertSame(0, $result->getScore());
        self::assertSame([], $result->getReasons());
    }

    public function testHtmlContentIncreasesTheScore(): void
    {
        $result = $this->check('<b>bold</b> text');

        self::assertSame(3, $result->getScore());
        self::assertSame(['contains_html'], $result->getReasons());
    }

    public function testKeywordScoreIsCappedAtThree(): void
    {
        $result = $this->check('casino crypto loan viagra seo backlink guest post');

        self::assertSame(['spam_keywords'], $result->getReasons());
        self::assertSame(3, $result->getScore());
    }

    public function testVeryLongMessageAddsReason(): void
    {
        $result = $this->check(str_repeat('word ', 361));

        self::assertSame(['very_long_message'], $result->getReasons());
        self::assertSame(2, $result->getScore());
    }
}
