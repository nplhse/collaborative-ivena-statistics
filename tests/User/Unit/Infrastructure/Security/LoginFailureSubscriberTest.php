<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\Tests\User\Doubles\InMemoryLoginFailureRecorder;
use App\User\Domain\Enum\LoginFailureKind;
use App\User\Infrastructure\Security\AccountLock\AccountLockedException;
use App\User\Infrastructure\Security\LoginFailureSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

#[CoversClass(LoginFailureSubscriber::class)]
final class LoginFailureSubscriberTest extends TestCase
{
    /** @var list<array{string, array<array-key, mixed>}> message and context of every warning */
    private array $warnings = [];

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
        $recorder = new InMemoryLoginFailureRecorder();
        $subscriber = new LoginFailureSubscriber($this->loggerSpy(), $recorder);
        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => 'Alice']], server: ['REMOTE_ADDR' => '203.0.113.10']);

        $subscriber->onLoginFailure($this->failureEvent($request, exception: $exception));

        self::assertSame([['kind' => $kind, 'identifier' => 'alice', 'ip' => '203.0.113.10']], $recorder->records);
    }

    public function testLogsTheHashedFormUsernameWithIpAgentAndException(): void
    {
        $subscriber = new LoginFailureSubscriber($this->loggerSpy(), new InMemoryLoginFailureRecorder());
        $request = Request::create('/login', Request::METHOD_POST, ['login' => ['username' => 'FormUser']], server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $subscriber->onLoginFailure($this->failureEvent($request));

        // The username is lower-cased before hashing, so "FormUser" and "formuser" give the same hash.
        self::assertSame([['security.login.failure', [
            'username_hash' => hash('sha256', 'formuser'),
            'ip' => '203.0.113.10',
            'user_agent' => 'TestAgent/1.0',
            'exception' => BadCredentialsException::class,
        ]]], $this->warnings);
    }

    /**
     * @return iterable<string, array{Request, ?Passport, ?string}>
     */
    public static function usernameSources(): iterable
    {
        yield 'legacy field wins over the form field' => [
            Request::create('/login', Request::METHOD_POST, ['_username' => 'legacy-user', 'login' => ['username' => 'form-user']]),
            null,
            hash('sha256', 'legacy-user'),
        ];
        yield 'user badge when the form fields are missing' => [
            Request::create('/login', Request::METHOD_POST),
            new Passport(new UserBadge('badge-user'), new PasswordCredentials('irrelevant')),
            hash('sha256', 'badge-user'),
        ];
        yield 'no source at all' => [Request::create('/login', Request::METHOD_POST), null, null];
    }

    #[DataProvider('usernameSources')]
    public function testLogsTheUsernameHashFromTheFirstAvailableSource(Request $request, ?Passport $passport, ?string $expectedHash): void
    {
        $subscriber = new LoginFailureSubscriber($this->loggerSpy(), new InMemoryLoginFailureRecorder());

        $subscriber->onLoginFailure($this->failureEvent($request, $passport));

        self::assertCount(1, $this->warnings);
        self::assertSame($expectedHash, $this->warnings[0][1]['username_hash']);
    }

    private function loggerSpy(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->warnings[] = [(string) $message, $context];
        });

        return $logger;
    }

    private function failureEvent(Request $request, ?Passport $passport = null, ?AuthenticationException $exception = null): LoginFailureEvent
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
