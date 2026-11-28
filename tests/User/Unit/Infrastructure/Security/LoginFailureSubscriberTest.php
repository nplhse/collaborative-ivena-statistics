<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Infrastructure\Security\AccountLock\AccountLockedException;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureRecorder;
use App\User\Infrastructure\Security\LoginFailureSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureSubscriberTest extends TestCase
{
    /** @var MockObject&LoggerInterface */
    private MockObject $logger;

    private LoginFailureSubscriber $subscriber;

    /** @var list<array<string, mixed>> rows written to login_failure_event */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $this->recorded[] = $data;

            return 1;
        });

        $this->subscriber = new LoginFailureSubscriber(
            $this->logger,
            new LoginFailureRecorder($connection, new MockClock('2026-11-28 10:00:00'), 'test-secret', new NullLogger()),
        );
    }

    public function testRecordsWrongCredentialsAsFailureAndLimitsAsRefused(): void
    {
        $this->logger->expects(self::exactly(3))->method('warning');
        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => 'Alice']], server: ['REMOTE_ADDR' => '203.0.113.10']);

        $this->subscriber->onLoginFailure($this->createFailureEvent($request));
        $this->subscriber->onLoginFailure($this->createFailureEvent($request, exception: new TooManyLoginAttemptsAuthenticationException()));
        $this->subscriber->onLoginFailure($this->createFailureEvent($request, exception: new AccountLockedException('Invalid credentials.')));

        self::assertSame(['failure', 'refused', 'refused'], array_column($this->recorded, 'kind'));
        self::assertSame(hash_hmac('sha256', 'identifier:alice', 'test-secret'), $this->recorded[0]['identifier_hash']);
        self::assertSame(hash_hmac('sha256', 'ip:203.0.113.10', 'test-secret'), $this->recorded[0]['ip_hash']);
    }

    public function testLogsHashFromLoginFormUsernameWithoutPassport(): void
    {
        $username = 'FormUser';
        $expectedHash = hash('sha256', mb_strtolower($username));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('security.login.failure', self::callback(static fn (array $context): bool => $expectedHash === $context['username_hash']
                && '203.0.113.10' === $context['ip']
                && 'TestAgent/1.0' === $context['user_agent']
                && BadCredentialsException::class === $context['exception']));

        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => $username]], server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $this->subscriber->onLoginFailure($this->createFailureEvent($request));
    }

    public function testPrefersLegacyUsernameOverFormField(): void
    {
        $expectedHash = hash('sha256', mb_strtolower('legacy-user'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('security.login.failure', self::callback(static fn (array $context): bool => $expectedHash === $context['username_hash']));

        $request = Request::create('/login', Request::METHOD_POST, [
            '_username' => 'legacy-user',
            'login' => ['username' => 'form-user'],
        ]);

        $this->subscriber->onLoginFailure($this->createFailureEvent($request));
    }

    public function testFallsBackToUserBadgeWhenFormFieldsMissing(): void
    {
        $expectedHash = hash('sha256', mb_strtolower('badge-user'));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('security.login.failure', self::callback(static fn (array $context): bool => $expectedHash === $context['username_hash']));

        $passport = new Passport(
            new UserBadge('badge-user'),
            new PasswordCredentials('irrelevant'),
        );

        $this->subscriber->onLoginFailure($this->createFailureEvent(
            Request::create('/login', Request::METHOD_POST),
            $passport,
        ));
    }

    public function testLogsNullUsernameHashWhenNoSourceAvailable(): void
    {
        $this->logger->expects(self::once())
            ->method('warning')
            ->with('security.login.failure', self::callback(static fn (array $context): bool => null === $context['username_hash']
                && BadCredentialsException::class === $context['exception']));

        $this->subscriber->onLoginFailure($this->createFailureEvent(
            Request::create('/login', Request::METHOD_POST),
        ));
    }

    private function createFailureEvent(Request $request, ?Passport $passport = null, ?AuthenticationException $exception = null): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception ?? new BadCredentialsException('Invalid credentials.'),
            $this->createStub(AuthenticatorInterface::class),
            $request,
            null,
            'main',
            $passport,
        );
    }
}
