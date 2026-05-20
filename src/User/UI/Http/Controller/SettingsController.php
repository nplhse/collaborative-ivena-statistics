<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\EmailVerifier;
use App\User\UI\Form\ForceChangePasswordType;
use App\User\UI\Form\SettingsEmailType;
use App\User\UI\Form\SettingsPasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditContext $auditContext,
    ) {
    }

    #[Route('/settings', name: 'app_settings_index')]
    public function index(): Response
    {
        return $this->render('@User/settings/index.html.twig');
    }

    #[Route('/settings/email/resend-verification', name: 'app_settings_resend_verification', methods: ['POST'])]
    public function resendVerification(
        Request $request,
        #[Autowire(service: 'limiter.verify_email_resend')] RateLimiterFactory $verifyEmailResendLimiter,
    ): RedirectResponse {
        $user = $this->requireUser();

        if (!$this->isCsrfTokenValid('resend_verification_email', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('flash.security.invalid_csrf');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'flash.settings.email.already_verified');

            return $this->redirectToRoute('app_settings_index');
        }

        $limiterKey = sprintf(
            'verify_email_resend_%d_%s_%s',
            (int) $user->getId(),
            sha1((string) $user->getEmail()),
            $request->getClientIp() ?? 'unknown'
        );
        $limit = $verifyEmailResendLimiter->create($limiterKey)->consume(1);

        if (!$limit->isAccepted()) {
            $this->addFlash('warning', 'flash.settings.email.resend_rate_limited');

            return $this->redirectToRoute('app_settings_index');
        }

        $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);
        $this->addFlash('success', 'flash.settings.email.verification_resent');

        return $this->redirectToRoute('app_settings_index');
    }

    #[Route('/settings/email', name: 'app_settings_email')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function email(Request $request): Response
    {
        $user = $this->requireUser();

        $form = $this->createForm(SettingsEmailType::class, [
            'email' => $user->getEmail(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();

            $newEmail = mb_strtolower(trim($data['email']));
            if ($newEmail !== $user->getEmail()) {
                $user->setEmail($newEmail);
                $user->setIsVerified(false);
                $this->auditContext->beginIntent('user.settings.email_changed', []);
                try {
                    $this->entityManager->flush();
                } finally {
                    $this->auditContext->endIntent();
                }
                $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);
            }

            $this->addFlash('success', 'flash.settings.email.updated_verify_required');

            return $this->redirectToRoute('app_settings_index');
        }

        return $this->render('@User/settings/email.html.twig', [
            'emailForm' => $form,
        ]);
    }

    #[Route('/settings/password', name: 'app_settings_password')]
    public function password(
        Request $request,
    ): Response {
        $user = $this->requireUser();

        $form = $this->createForm(SettingsPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            if (!\is_string($currentPassword) || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('danger', 'flash.settings.password.current_invalid');

                return $this->redirectToRoute('app_settings_password');
            }

            $plainPassword = $form->get('plainPassword')->getData();
            if (!\is_string($plainPassword) || '' === $plainPassword) {
                throw new \LogicException('Password form did not provide a new password.');
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->setCredentialsExpired(false);
            $this->auditContext->beginIntent('user.settings.password_changed', []);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', 'flash.settings.password.updated');

            return $this->redirectToRoute('app_settings_index');
        }

        return $this->render('@User/settings/password.html.twig', [
            'passwordForm' => $form,
        ]);
    }

    #[Route('/settings/force-password-change', name: 'app_force_change_password')]
    public function forceChangePassword(
        Request $request,
    ): Response {
        $user = $this->requireUser();

        $form = $this->createForm(ForceChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (!\is_string($plainPassword) || '' === $plainPassword) {
                throw new \LogicException('Forced password form did not provide a new password.');
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->setCredentialsExpired(false);
            $this->auditContext->beginIntent('user.settings.forced_password_changed', []);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', 'flash.settings.password.updated');

            return $this->redirectToRoute('app_default');
        }

        return $this->render('@User/settings/force_password_change.html.twig', [
            'passwordForm' => $form,
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        return $user;
    }
}
