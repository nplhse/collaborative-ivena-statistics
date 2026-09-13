<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Infrastructure\Security\AuthenticationEntryPoint;
use App\User\Infrastructure\Security\SwitchUserExitExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

final class SwitchUserExitExceptionSubscriberTest extends TestCase
{
    public function testIgnoresUnrelatedException(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/?_switch_user=_exit'),
            new AccessDeniedException('Switch User failed.'),
        );

        $this->createSubscriber($this->createStub(TokenInterface::class))->onKernelException($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    public function testIgnoresCredentialsExceptionWithoutExitParameter(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/login/confirm', Request::METHOD_POST),
            new AuthenticationCredentialsNotFoundException('No authenticated user to confirm password for.'),
        );

        $this->createSubscriber(null)->onKernelException($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    public function testIgnoresSubRequest(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/?_switch_user=_exit'),
            new AuthenticationCredentialsNotFoundException('Could not find original Token object.'),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->createSubscriber($this->createStub(TokenInterface::class))->onKernelException($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    public function testRedirectsAuthenticatedUserToHomepage(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/?_switch_user=_exit'),
            new AuthenticationCredentialsNotFoundException('Could not find original Token object.'),
        );

        $this->createSubscriber($this->createStub(TokenInterface::class))->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertTrue($event->isPropagationStopped());
        self::assertTrue($event->isAllowingCustomResponseCode());
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    public function testRedirectsAnonymousUserViaEntryPoint(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/?_switch_user=_exit'),
            new AuthenticationCredentialsNotFoundException('Could not find original Token object.'),
        );

        $this->createSubscriber(null)->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertTrue($event->isPropagationStopped());
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testDetectsExitParameterFromUnsafeRequestBody(): void
    {
        $event = $this->createExceptionEvent(
            Request::create('/', Request::METHOD_POST, ['_switch_user' => '_exit']),
            new AuthenticationCredentialsNotFoundException('Could not find original Token object.'),
        );

        $this->createSubscriber($this->createStub(TokenInterface::class))->onKernelException($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    private function createSubscriber(?TokenInterface $token): SwitchUserExitExceptionSubscriber
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => match ($route) {
            'app_default' => '/',
            'app_login' => '/login',
            default => throw new \InvalidArgumentException($route),
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $trustResolver = $this->createStub(AuthenticationTrustResolverInterface::class);
        $trustResolver->method('isRememberMe')->willReturn(false);

        return new SwitchUserExitExceptionSubscriber(
            $urlGenerator,
            new AuthenticationEntryPoint($trustResolver, $tokenStorage, $urlGenerator),
            $tokenStorage,
        );
    }

    private function createExceptionEvent(
        Request $request,
        \Throwable $throwable,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ExceptionEvent {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            $throwable,
        );
    }
}
