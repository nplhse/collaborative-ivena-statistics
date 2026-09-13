<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * Treats switch-user exit without a SwitchUserToken as a no-op.
 *
 * Symfony's SwitchUserListener throws AuthenticationCredentialsNotFoundException
 * when `_switch_user=_exit` is requested without an impersonation token. That
 * must not 500, and must not be captured by Sentry (priority 128).
 *
 * @psalm-suppress UnusedClass
 */
final readonly class SwitchUserExitExceptionSubscriber
{
    private const string PARAMETER = '_switch_user';

    private const string EXIT_VALUE = '_exit';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private AuthenticationEntryPoint $authenticationEntryPoint,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof AuthenticationCredentialsNotFoundException) {
            return;
        }

        if (!$this->isSwitchUserExit($event->getRequest())) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token instanceof TokenInterface) {
            $response = new RedirectResponse($this->urlGenerator->generate('app_default'));
        } else {
            $response = $this->authenticationEntryPoint->start($event->getRequest(), $throwable);
        }

        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }

    private function isSwitchUserExit(Request $request): bool
    {
        $username = $request->query->get(self::PARAMETER)
            ?? (!\in_array($request->getMethod(), ['GET', 'HEAD'], true) ? $request->request->get(self::PARAMETER) : null);

        if (null === $username || '' === $username) {
            $username = $request->headers->get(self::PARAMETER);
        }

        return self::EXIT_VALUE === $username;
    }
}
