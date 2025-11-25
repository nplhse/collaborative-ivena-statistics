<?php

namespace App\User\UI\Http\Controller;

use App\User\UI\Form\LoginType;
use App\User\UI\Http\DTO\LoginTypeDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** @psalm-suppress UnusedClass */
final class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_default');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        $loginFormDTO = new LoginTypeDTO();
        $loginFormDTO->setUsername($authenticationUtils->getLastUsername());

        $form = $this->createForm(LoginType::class, $loginFormDTO);

        return $this->render('@User/security/login.html.twig', [
            'form' => $form,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
