<?php

namespace App\Security;

use App\DataTransferObjects\LoginTypeDTO;
use App\Form\LoginType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const string LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        $form = $this->formFactory->create(LoginType::class);
        $form->handleRequest($request);

        /** @var LoginTypeDTO $loginTypeDTO */
        $loginTypeDTO = $form->getData();

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $loginTypeDTO->getUsername());

        return new Passport(
            new UserBadge($loginTypeDTO->getUsername()),
            new PasswordCredentials($loginTypeDTO->getPassword()),
            $this->getBadges($request, $loginTypeDTO)
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
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

    /**
     * @return BadgeInterface[]
     */
    private function getBadges(Request $request, LoginTypeDTO $loginTypeDTO): array
    {
        $badges = [];
        $badges[] = new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token'));

        if ($loginTypeDTO->getRememberMe()) {
            $badges[] = new RememberMeBadge();
        }

        return $badges;
    }
}
