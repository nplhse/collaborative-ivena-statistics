<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\EmailVerifier;
use App\User\UI\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailVerifier $emailVerifier,
        private readonly AuditContext $auditContext,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LocaleResolver $localeResolver,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
    ): Response {
        if ($this->getUser() instanceof UserInterface) {
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

            $user = new User()
                ->setUsername($data['username'])
                ->setEmail($data['email'])
                ->setIsVerified(false)
                ->setLocale($this->localeResolver->resolve($request, null));

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $this->entityManager->persist($user);
            $this->auditContext->beginIntent('user.registered', []);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $userId = $user->getId();
            if (null !== $userId) {
                $this->eventDispatcher->dispatch(new UserRegistered($userId));
            }

            $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->render('@User/registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/register/check-email', name: 'app_register_check_email')]
    public function checkEmail(): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_default');
        }

        return $this->render('@User/registration/check_email.html.twig');
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(
        Request $request,
    ): RedirectResponse {
        $id = $request->query->get('id');
        if (!\is_string($id) || '' === $id) {
            throw $this->createNotFoundException('flash.registration.verify.missing_id');
        }

        $user = $this->entityManager->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('flash.registration.verify.user_not_found');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('danger', $exception->getReason());

            return $this->redirectToRoute('app_login');
        }

        $user->setIsVerified(true);
        $this->auditContext->beginIntent('user.email_verified', []);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->addFlash('success', new TranslatableMessage('flash.registration.verify.success', domain: 'user'));

        return $this->redirectToRoute('app_login');
    }
}
