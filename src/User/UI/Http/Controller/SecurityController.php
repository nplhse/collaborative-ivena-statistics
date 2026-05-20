<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\UI\Form\ConfirmPasswordType;
use App\User\UI\Form\LoginType;
use App\User\UI\Http\DTO\ConfirmPasswordTypeDTO;
use App\User\UI\Http\DTO\LoginTypeDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** @psalm-suppress UnusedClass */
final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly CookieConsentService $cookieConsentService,
    ) {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(Request $request): Response
    {
        $user = $this->getUser();
        if ($user instanceof UserInterface) {
            if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
                return $this->redirectToRoute('app_confirm_password');
            }

            return $this->redirectToRoute('app_default');
        }

        $consent = $this->cookieConsentService->resolveForRequest($request, null);
        $hasConsentDecision = $consent->getDecidedAt() instanceof \DateTimeImmutable;
        $showConsentHint = '0' !== $request->query->getString('showConsentHint', '1');

        // get the login error if there is one
        $error = $this->authenticationUtils->getLastAuthenticationError();
        $loginFormDTO = new LoginTypeDTO();
        $loginFormDTO->setUsername($this->authenticationUtils->getLastUsername());
        $form = $this->createForm(LoginType::class, $loginFormDTO);

        return $this->render('@User/security/login.html.twig', [
            'form' => $form,
            'error' => $error,
            'hasConsentDecision' => $hasConsentDecision,
            'showConsentHint' => $showConsentHint,
        ]);
    }

    #[Route(path: '/login/confirm', name: 'app_confirm_password')]
    public function confirmPassword(): Response
    {
        if (!$this->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $this->redirectToRoute('app_login');
        }

        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('app_default');
        }

        $error = $this->authenticationUtils->getLastAuthenticationError();
        $form = $this->createForm(ConfirmPasswordType::class, new ConfirmPasswordTypeDTO());

        return $this->render('@User/security/confirm_password.html.twig', [
            'form' => $form,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
