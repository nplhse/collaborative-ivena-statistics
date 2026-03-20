<?php

namespace App\User\UI\Http\Controller;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\EmailVerifier;
use App\User\Infrastructure\Security\LoginFormAuthenticator;
use App\User\UI\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        EmailVerifier $emailVerifier,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $loginFormAuthenticator,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_default');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{username: string, email: string} $data */
            $data = $form->getData();
            $plainPassword = $form->get('plainPassword')->getData();
            if (!\is_string($plainPassword) || '' === $plainPassword) {
                throw new \LogicException('Registration form did not provide a password.');
            }

            $user = (new User())
                ->setUsername($data['username'])
                ->setEmail($data['email'])
                ->setIsVerified(false);

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            $emailVerifier->sendEmailConfirmation('app_verify_email', $user);
            $this->addFlash('success', 'flash.registration.success_verify_required');

            $authenticatedResponse = $userAuthenticator->authenticateUser($user, $loginFormAuthenticator, $request);

            return $authenticatedResponse ?? $this->redirectToRoute('app_default');
        }

        return $this->render('@User/registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        EmailVerifier $emailVerifier,
    ): Response {
        $id = $request->query->get('id');
        if (!\is_string($id) || '' === $id) {
            throw $this->createNotFoundException('flash.registration.verify.missing_id');
        }

        $user = $entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('flash.registration.verify.user_not_found');
        }

        try {
            $emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('danger', $exception->getReason());

            return $this->redirectToRoute('app_login');
        }

        $user->setIsVerified(true);
        $entityManager->flush();

        $this->addFlash('success', 'flash.registration.verify.success');

        return $this->redirectToRoute('app_login');
    }
}
