<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
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
        $passport = $event->getPassport();
        $username = $request->request->getString('_username');

        if ('' === $username && $passport instanceof \Symfony\Component\Security\Http\Authenticator\Passport\Passport) {
            $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
            if ($userBadge instanceof \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge) {
                $username = $userBadge->getUserIdentifier();
            }
        }

        $this->logger->warning('security.login.failure', [
            'username_hash' => '' !== $username ? hash('sha256', mb_strtolower($username)) : null,
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'exception' => $event->getException()::class,
        ]);
    }
}
