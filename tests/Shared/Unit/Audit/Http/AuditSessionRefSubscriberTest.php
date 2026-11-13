<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Http\AuditSessionRefSubscriber;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Uid\Uuid;

final class AuditSessionRefSubscriberTest extends TestCase
{
    private const string EXISTING_REF = '0193a4b2-6f1e-4c55-9d3e-2a1b0c9d8e7f';

    public function testLoginWithoutPreviousTokenStartsNewReference(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(self::EXISTING_REF);

        $this->subscriber($context, $request)->onTokenCreated($this->tokenCreated('alice'));

        $ref = $request->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE);
        self::assertIsString($ref);
        self::assertTrue(Uuid::isValid($ref));
        self::assertNotSame(self::EXISTING_REF, $ref);
        self::assertSame($ref, $context->getSessionRef());
    }

    public function testLoginOfAnotherUserInTheSameSessionStartsNewReference(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(self::EXISTING_REF);

        $this->subscriber($context, $request, previousUser: 'alice')->onTokenCreated($this->tokenCreated('bob'));

        self::assertNotSame(self::EXISTING_REF, $context->getSessionRef());
    }

    public function testReauthenticationOfTheSameUserKeepsReference(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(self::EXISTING_REF);

        $this->subscriber($context, $request, previousUser: 'alice')->onTokenCreated($this->tokenCreated('alice'));

        self::assertSame(self::EXISTING_REF, $context->getSessionRef());
        self::assertSame(self::EXISTING_REF, $request->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE));
    }

    public function testTokenWithoutSessionSetsNoReference(): void
    {
        $context = new AuditContext();

        $this->subscriber($context, Request::create('/'))->onTokenCreated($this->tokenCreated('alice'));

        self::assertNull($context->getSessionRef());
    }

    public function testTokenOutsideARequestSetsNoReference(): void
    {
        $context = new AuditContext();

        $this->subscriber($context, null)->onTokenCreated($this->tokenCreated('alice'));

        self::assertNull($context->getSessionRef());
    }

    public function testSubRequestIsIgnored(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(self::EXISTING_REF, previous: true);

        $this->subscriber($context, $request, new User())->onKernelRequest(
            new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST),
        );

        self::assertNull($context->getSessionRef());
    }

    public function testRequestWithoutPreviousSessionGetsNoReference(): void
    {
        $context = new AuditContext();
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('getUser');

        new AuditSessionRefSubscriber($context, $security, new RequestStack(), new TokenStorage())
            ->onKernelRequest($this->requestEvent(Request::create('/')));

        self::assertNull($context->getSessionRef());
    }

    public function testAnonymousRequestWithSessionGetsNoReference(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(null, previous: true);

        $this->subscriber($context, $request)->onKernelRequest($this->requestEvent($request));

        self::assertNull($context->getSessionRef());
        self::assertFalse($request->getSession()->has(AuditSessionRefSubscriber::SESSION_ATTRIBUTE));
    }

    public function testAuthenticatedRequestUsesStoredReference(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(self::EXISTING_REF, previous: true);

        $this->subscriber($context, $request, new User())->onKernelRequest($this->requestEvent($request));

        self::assertSame(self::EXISTING_REF, $context->getSessionRef());
    }

    public function testAuthenticatedSessionFromBeforeTheReferenceGetsOne(): void
    {
        $context = new AuditContext();
        $request = $this->requestWithSession(null, previous: true);

        $this->subscriber($context, $request, new User())->onKernelRequest($this->requestEvent($request));

        $ref = $request->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE);
        self::assertIsString($ref);
        self::assertTrue(Uuid::isValid($ref));
        self::assertSame($ref, $context->getSessionRef());
    }

    private function subscriber(AuditContext $context, ?Request $request, ?User $user = null, ?string $previousUser = null): AuditSessionRefSubscriber
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        if ($request instanceof Request) {
            $requestStack->push($request);
        }

        $tokenStorage = new TokenStorage();
        if (null !== $previousUser) {
            $tokenStorage->setToken(new UsernamePasswordToken(new User()->setUsername($previousUser), 'main', []));
        }

        return new AuditSessionRefSubscriber($context, $security, $requestStack, $tokenStorage);
    }

    private function tokenCreated(string $username): AuthenticationTokenCreatedEvent
    {
        $user = new User()->setUsername($username);

        return new AuthenticationTokenCreatedEvent(
            new UsernamePasswordToken($user, 'main', []),
            new SelfValidatingPassport(new UserBadge($username, static fn (): User => $user)),
        );
    }

    private function requestWithSession(?string $ref, bool $previous = false): Request
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $ref) {
            $session->set(AuditSessionRefSubscriber::SESSION_ATTRIBUTE, $ref);
        }

        $request = Request::create('/');
        $request->setSession($session);
        if ($previous) {
            $request->cookies->set($session->getName(), 'session-cookie');
        }

        return $request;
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
