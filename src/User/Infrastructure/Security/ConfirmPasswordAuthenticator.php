<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\UI\Form\ConfirmPasswordType;
use App\User\UI\Http\DTO\ConfirmPasswordTypeDTO;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** @psalm-suppress UnusedClass */
final class ConfirmPasswordAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    private const string LOGIN_ROUTE = 'app_confirm_password';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FormFactoryInterface $formFactory,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            throw new AuthenticationCredentialsNotFoundException('No authenticated user to confirm password for.');
        }

        $form = $this->formFactory->create(ConfirmPasswordType::class);
        $form->handleRequest($request);

        /** @var ConfirmPasswordTypeDTO $confirmPasswordDTO */
        $confirmPasswordDTO = $form->getData();

        return new Passport(
            new UserBadge($token->getUserIdentifier()),
            new PasswordCredentials($confirmPasswordDTO->password),
            [
                new CsrfTokenBadge('confirm_password', $request->getPayload()->getString('_csrf_token')),
            ]
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): RedirectResponse
    {
        $user = $token->getUser();

        if ($user instanceof User && $user->isCredentialsExpired()) {
            return new RedirectResponse($this->urlGenerator->generate('app_force_change_password'));
        }

        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        if (null !== $targetPath) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_default'));
    }

    #[\Override]
    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
