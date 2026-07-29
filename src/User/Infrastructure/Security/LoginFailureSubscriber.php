<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/** @psalm-suppress UnusedClass */
final readonly class LoginFailureSubscriber
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'monolog.logger.security')]
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $username = $this->resolveAttemptedUsername($request, $event->getPassport());

        $this->logger->warning('security.login.failure', [
            'username_hash' => '' !== $username ? hash('sha256', mb_strtolower($username)) : null,
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'exception' => $event->getException()::class,
        ]);
    }

    private function resolveAttemptedUsername(Request $request, ?Passport $passport): string
    {
        $username = $request->request->getString('_username');
        if ('' !== $username) {
            return $username;
        }

        $login = $request->request->all('login');
        $formUsername = $login['username'] ?? '';
        if (\is_string($formUsername) && '' !== $formUsername) {
            return $formUsername;
        }

        if ($passport instanceof Passport) {
            $userBadge = $passport->getBadge(UserBadge::class);
            if ($userBadge instanceof UserBadge) {
                return $userBadge->getUserIdentifier();
            }
        }

        return '';
    }
}
