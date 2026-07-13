<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Application\Locale\UserLocalePreferenceUpdater;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Application\UserSettingsUpdater;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\EmailVerifier;
use App\User\UI\Form\ForceChangePasswordType;
use App\User\UI\Form\SettingsEmailType;
use App\User\UI\Form\SettingsLocaleType;
use App\User\UI\Form\SettingsNotificationsType;
use App\User\UI\Form\SettingsPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly UserSettingsUpdater $userSettingsUpdater,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditContext $auditContext,
        private readonly UserLocalePreferenceUpdater $userLocalePreferenceUpdater,
        private readonly LocaleResolver $localeResolver,
    ) {
    }

    #[Route('/settings', name: 'app_settings_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $automaticDefaultLocale = $this->localeResolver->resolveAutomaticDefault($request);

        $currentLocale = $user->hasExplicitLocale()
            ? (string) $user->getLocale()
            : $automaticDefaultLocale;
        if (!SupportedLocales::isSupported($currentLocale)) {
            $currentLocale = SupportedLocales::DEFAULT;
        }

        $localeForm = $this->createForm(SettingsLocaleType::class, ['locale' => $currentLocale], [
            'automatic_default_locale' => $automaticDefaultLocale,
        ]);
        $localeForm->handleRequest($request);

        if ($localeForm->isSubmitted() && $localeForm->isValid()) {
            /** @var array{locale: string} $data */
            $data = $localeForm->getData();

            $this->auditContext->beginIntent('user.settings.locale_updated', []);
            try {
                $cookie = $this->userLocalePreferenceUpdater->update($user, $data['locale'], $request);
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.locale.updated', domain: 'user'));

            $response = $this->redirectToRoute('app_settings_index');
            $response->headers->setCookie($cookie);

            return $response;
        }

        return $this->render('@User/settings/index.html.twig', [
            'localeForm' => $localeForm,
        ]);
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
            $this->addFlash('info', new TranslatableMessage('flash.settings.email.already_verified', domain: 'user'));

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
            $this->addFlash('warning', new TranslatableMessage('flash.settings.email.resend_rate_limited', domain: 'user'));

            return $this->redirectToRoute('app_settings_index');
        }

        $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);
        $this->addFlash('success', new TranslatableMessage('flash.settings.email.verification_resent', domain: 'user'));

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
                $this->auditContext->beginIntent('user.settings.email_changed', []);
                try {
                    $this->userSettingsUpdater->updateEmail($user, $newEmail);
                } finally {
                    $this->auditContext->endIntent();
                }
                $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.email.updated_verify_required', domain: 'user'));

            return $this->redirectToRoute('app_settings_index');
        }

        return $this->render('@User/settings/email.html.twig', [
            'emailForm' => $form,
        ]);
    }

    #[Route('/settings/password', name: 'app_settings_password')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function password(
        Request $request,
    ): Response {
        $user = $this->requireUser();

        $form = $this->createForm(SettingsPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            if (!\is_string($currentPassword) || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('danger', new TranslatableMessage('flash.settings.password.current_invalid', domain: 'user'));

                return $this->redirectToRoute('app_settings_password');
            }

            $plainPassword = $form->get('plainPassword')->getData();
            if (!\is_string($plainPassword) || '' === $plainPassword) {
                throw new \LogicException('Password form did not provide a new password.');
            }

            $this->auditContext->beginIntent('user.settings.password_changed', []);
            try {
                $this->userSettingsUpdater->updatePassword(
                    $user,
                    $this->passwordHasher->hashPassword($user, $plainPassword),
                );
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.password.updated', domain: 'user'));

            return $this->redirectToRoute('app_settings_index');
        }

        return $this->render('@User/settings/password.html.twig', [
            'passwordForm' => $form,
        ]);
    }

    #[Route('/settings/notifications', name: 'app_settings_notifications')]
    public function notifications(Request $request): Response
    {
        $user = $this->requireUser();

        $form = $this->createForm(SettingsNotificationsType::class, [
            'receivesMonthlySubmissionReminder' => $user->receivesMonthlySubmissionReminder(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{receivesMonthlySubmissionReminder?: bool} $data */
            $data = $form->getData();

            $this->auditContext->beginIntent('user.settings.notifications_updated', []);
            try {
                $this->userSettingsUpdater->updateNotifications(
                    $user,
                    $data['receivesMonthlySubmissionReminder'] ?? false,
                );
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.notifications.updated', domain: 'user'));

            return $this->redirectToRoute('app_settings_notifications');
        }

        return $this->render('@User/settings/notifications.html.twig', [
            'notificationsForm' => $form,
        ]);
    }

    #[Route('/settings/force-password-change', name: 'app_force_change_password')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
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

            $this->auditContext->beginIntent('user.settings.forced_password_changed', []);
            try {
                $this->userSettingsUpdater->updatePassword(
                    $user,
                    $this->passwordHasher->hashPassword($user, $plainPassword),
                );
            } finally {
                $this->auditContext->endIntent();
            }

            $this->addFlash('success', new TranslatableMessage('flash.settings.password.updated', domain: 'user'));

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
