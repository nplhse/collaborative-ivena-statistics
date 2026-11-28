<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\Tests\User\Doubles\InMemoryLoginFailureRecorder;
use App\User\Domain\Enum\LoginFailureKind;
use App\User\Infrastructure\Security\AccountLock\AccountLockedException;
use App\User\Infrastructure\Security\LoginFailureSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

    private InMemoryLoginFailureRecorder $recorder;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->recorder = new InMemoryLoginFailureRecorder();
        $this->subscriber = new LoginFailureSubscriber($this->logger, $this->recorder);
    }

    /** @return iterable<string, array{AuthenticationException, LoginFailureKind}> */
    public static function exceptionKinds(): iterable
    {
        yield 'wrong credentials' => [new BadCredentialsException('Invalid credentials.'), LoginFailureKind::Failure];
        yield 'login throttling' => [new TooManyLoginAttemptsAuthenticationException(), LoginFailureKind::Refused];
        yield 'locked account' => [new AccountLockedException('Invalid credentials.'), LoginFailureKind::Refused];
    }

    #[DataProvider('exceptionKinds')]
    public function testRecordsTheFailureWithUsernameAndIp(AuthenticationException $exception, LoginFailureKind $kind): void
    {
        $this->logger->expects(self::once())->method('warning');
        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => 'Alice']], server: ['REMOTE_ADDR' => '203.0.113.10']);

        $this->subscriber->onLoginFailure($this->createFailureEvent($request, exception: $exception));

        self::assertSame([['kind' => $kind, 'identifier' => 'alice', 'ip' => '203.0.113.10']], $this->recorder->records);
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
