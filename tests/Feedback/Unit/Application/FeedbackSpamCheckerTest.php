<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackSpamChecker;
use PHPUnit\Framework\TestCase;

final class FeedbackSpamCheckerTest extends TestCase
{
    private function createChecker(): FeedbackSpamChecker
    {
        return new FeedbackSpamChecker(
            minSubmissionSeconds: 4,
            longMessageThreshold: 1800,
            anonymousThreshold: 6,
            authenticatedThreshold: 8,
            authenticatedScoreBonus: 2,
            keywords: ['casino', 'crypto', 'loan', 'viagra', 'seo', 'backlink', 'guest post'],
        );
    }

    public function testHoneypotFilledIsSpam(): void
    {
        $result = $this->createChecker()->check(
            message: 'Legitimate looking text.',
            honeypotValue: 'https://spam.example',
            renderedAtTimestamp: time() - 10,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertTrue($result->isSpam());
        self::assertContains('honeypot_filled', $result->getReasons());
    }

    public function testVeryFastSubmissionIsSpamForAnonymous(): void
    {
        $now = time();
        $result = $this->createChecker()->check(
            message: 'Quick submission',
            honeypotValue: '',
            renderedAtTimestamp: $now - 1,
            submittedAtTimestamp: $now,
            isAuthenticated: false,
        );

        self::assertTrue($result->isSpam());
        self::assertContains('submitted_too_fast', $result->getReasons());
    }

    public function testMultipleLinksIncreaseSpamScore(): void
    {
        $result = $this->createChecker()->check(
            message: 'See https://a.example and https://b.example for details.',
            honeypotValue: '',
            renderedAtTimestamp: time() - 20,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertGreaterThanOrEqual(3, $result->getScore());
        self::assertContains('multiple_urls', $result->getReasons());
    }

    public function testHtmlContentIncreasesSpamScore(): void
    {
        $result = $this->createChecker()->check(
            message: '<a href="https://spam.example">click</a>',
            honeypotValue: '',
            renderedAtTimestamp: time() - 20,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertGreaterThanOrEqual(3, $result->getScore());
        self::assertContains('contains_html', $result->getReasons());
    }

    public function testAuthenticatedUserIsLessStrict(): void
    {
        $now = time();
        $message = 'Visit https://a.example and https://b.example';

        $anonymousResult = $this->createChecker()->check(
            message: $message,
            honeypotValue: '',
            renderedAtTimestamp: $now - 1,
            submittedAtTimestamp: $now,
            isAuthenticated: false,
        );
        $authenticatedResult = $this->createChecker()->check(
            message: $message,
            honeypotValue: '',
            renderedAtTimestamp: $now - 1,
            submittedAtTimestamp: $now,
            isAuthenticated: true,
        );

        self::assertTrue($anonymousResult->isSpam());
        self::assertFalse($authenticatedResult->isSpam());
        self::assertGreaterThan(0, $authenticatedResult->getScore());
        self::assertGreaterThan($authenticatedResult->getScore(), $anonymousResult->getScore());
    }

    public function testMissingRenderedTimestampAddsReasonForAnonymous(): void
    {
        $result = $this->createChecker()->check(
            message: 'No rendered timestamp provided.',
            honeypotValue: '',
            renderedAtTimestamp: null,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertContains('missing_render_timestamp', $result->getReasons());
        self::assertGreaterThanOrEqual(1, $result->getScore());
    }

    public function testMissingRenderedTimestampDoesNotPenalizeAuthenticated(): void
    {
        $result = $this->createChecker()->check(
            message: 'No rendered timestamp provided.',
            honeypotValue: '',
            renderedAtTimestamp: null,
            submittedAtTimestamp: time(),
            isAuthenticated: true,
        );

        self::assertNotContains('missing_render_timestamp', $result->getReasons());
    }

    public function testKeywordScoreIsCappedAtThree(): void
    {
        $result = $this->createChecker()->check(
            message: 'casino crypto loan viagra seo backlink guest post',
            honeypotValue: '',
            renderedAtTimestamp: time() - 20,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertContains('spam_keywords', $result->getReasons());
        self::assertSame(3, $result->getScore());
    }

    public function testVeryLongMessageAddsReason(): void
    {
        $result = $this->createChecker()->check(
            message: str_repeat('a', 1801),
            honeypotValue: '',
            renderedAtTimestamp: time() - 20,
            submittedAtTimestamp: time(),
            isAuthenticated: false,
        );

        self::assertContains('very_long_message', $result->getReasons());
    }
}
