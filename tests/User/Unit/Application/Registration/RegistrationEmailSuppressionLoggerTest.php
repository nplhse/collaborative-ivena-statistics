<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Registration;

use App\User\Application\Registration\RegistrationEmailSuppressionLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RegistrationEmailSuppressionLoggerTest extends TestCase
{
    public function testLogsHashedEmailAndIpWithoutPlaintextAddress(): void
    {
        $email = 'Spam.User@Example.RU';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'registration suppressed by blocked email domain',
                self::callback(static function (array $context) use ($email): bool {
                    $serialized = json_encode($context);
                    self::assertIsString($serialized);
                    self::assertStringNotContainsString($email, $serialized);
                    self::assertStringNotContainsString('spam.user@example.ru', $serialized);
                    self::assertSame(sha1('spam.user@example.ru'), $context['email_hash']);
                    self::assertSame(sha1('127.0.0.1'), $context['client_ip_hash']);
                    self::assertSame('ru', $context['matched_suffix']);

                    return true;
                }),
            );

        $rejectLogger = new RegistrationEmailSuppressionLogger($logger);
        $rejectLogger->log($email, '127.0.0.1', 'ru');
    }

    public function testHashesUnknownIpWhenClientIpIsMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'registration suppressed by blocked email domain',
                self::callback(static fn (array $context): bool => sha1('unknown') === $context['client_ip_hash']
                    && null === $context['matched_suffix']),
            );

        $rejectLogger = new RegistrationEmailSuppressionLogger($logger);
        $rejectLogger->log('user@example.ru', null, null);
    }
}
