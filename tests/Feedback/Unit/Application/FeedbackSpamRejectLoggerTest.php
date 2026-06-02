<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackSpamCheckResult;
use App\Feedback\Application\FeedbackSpamRejectLogger;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FeedbackSpamRejectLoggerTest extends TestCase
{
    public function testLogsSpamRejectWithScoreAndHashedGuestSubmitter(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback submission rejected as spam',
                self::callback(static fn (array $context): bool => 100 === $context['spam_score']
                    && ['honeypot_filled'] === $context['spam_reasons']
                    && 'guest:'.sha1('honeypot@example.test') === $context['submitter']
                    && sha1('127.0.0.1') === $context['client_ip_hash']
                    && false === $context['is_authenticated']
                    && null === $context['rate_limiter_hit']),
            );

        $rejectLogger = new FeedbackSpamRejectLogger($logger);
        $rejectLogger->logRejected(
            user: null,
            guestEmail: 'honeypot@example.test',
            clientIp: '127.0.0.1',
            spamDecision: new FeedbackSpamCheckResult(true, 100, ['honeypot_filled']),
            rateLimiterHit: '',
        );
    }

    public function testLogsRateLimiterRejectForAuthenticatedUser(): void
    {
        $user = new User();
        $user->setEmail('user@example.test');
        $user->setUsername('test-user');

        $ref = new \ReflectionClass($user);
        $idProp = $ref->getProperty('id');
        $idProp->setValue($user, 42);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback submission rejected as spam',
                self::callback(static fn (array $context): bool => null === $context['spam_score']
                    && [] === $context['spam_reasons']
                    && 'user:42' === $context['submitter']
                    && true === $context['is_authenticated']
                    && 'anonymous_ip' === $context['rate_limiter_hit']),
            );

        $rejectLogger = new FeedbackSpamRejectLogger($logger);
        $rejectLogger->logRejected(
            user: $user,
            guestEmail: null,
            clientIp: '10.0.0.1',
            spamDecision: null,
            rateLimiterHit: 'anonymous_ip',
        );
    }

    public function testLogsGuestUnknownWhenGuestEmailIsBlank(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback submission rejected as spam',
                self::callback(static fn (array $context): bool => 'guest:unknown' === $context['submitter']
                    && sha1('unknown') === $context['client_ip_hash']),
            );

        $rejectLogger = new FeedbackSpamRejectLogger($logger);
        $rejectLogger->logRejected(
            user: null,
            guestEmail: '   ',
            clientIp: null,
            spamDecision: null,
            rateLimiterHit: '',
        );
    }

    public function testLogsUserUnknownWhenAuthenticatedUserHasNoId(): void
    {
        $user = new User();
        $user->setEmail('no-id@example.test');
        $user->setUsername('no-id');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback submission rejected as spam',
                self::callback(static fn (array $context): bool => 'user:unknown' === $context['submitter']
                    && true === $context['is_authenticated']),
            );

        $rejectLogger = new FeedbackSpamRejectLogger($logger);
        $rejectLogger->logRejected(
            user: $user,
            guestEmail: null,
            clientIp: '127.0.0.2',
            spamDecision: null,
            rateLimiterHit: '',
        );
    }
}
