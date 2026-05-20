<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** @psalm-suppress UnusedClass */
final readonly class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private AuthenticationTrustResolverInterface $trustResolver,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): RedirectResponse
    {
        $token = $this->tokenStorage->getToken();

        if ($token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface && $this->trustResolver->isRememberMe($token)) {
            return new RedirectResponse($this->urlGenerator->generate('app_confirm_password'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
