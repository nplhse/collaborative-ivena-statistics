<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Registration;

use App\User\Application\Registration\RegistrationEmailSuppressionLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RegistrationEmailSuppressionLogger::class)]
final class RegistrationEmailSuppressionLoggerTest extends TestCase
{
    /** @var list<array{string, array<array-key, mixed>}> message and context of every info entry */
    private array $entries = [];

    public function testLogsHashedEmailAndIpWithoutPlaintextAddress(): void
    {
        $logger = new RegistrationEmailSuppressionLogger($this->loggerSpy());

        $logger->log('Spam.User@Example.RU', '127.0.0.1', 'ru');

        // The address is trimmed and lower-cased before hashing; the context holds no address in plain text.
        self::assertSame([['registration suppressed by blocked email domain', [
            'email_hash' => sha1('spam.user@example.ru'),
            'client_ip_hash' => sha1('127.0.0.1'),
            'matched_suffix' => 'ru',
        ]]], $this->entries);
    }

    public function testHashesUnknownIpWhenClientIpIsMissing(): void
    {
        $logger = new RegistrationEmailSuppressionLogger($this->loggerSpy());

        $logger->log('user@example.ru', null, null);

        self::assertSame([['registration suppressed by blocked email domain', [
            'email_hash' => sha1('user@example.ru'),
            'client_ip_hash' => sha1('unknown'),
            'matched_suffix' => null,
        ]]], $this->entries);
    }

    private function loggerSpy(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->entries[] = [(string) $message, $context];
        });

        return $logger;
    }
}
