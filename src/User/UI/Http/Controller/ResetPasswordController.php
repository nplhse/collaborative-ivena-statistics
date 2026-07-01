<?php

declare(strict_types=1);

namespace App\User\UI\Http\Controller;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Domain\Entity\User;
use App\User\UI\Form\ResetPasswordFormType;
use App\User\UI\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionalMailer $transactionalMailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditContext $auditContext,
        private readonly LocaleResolver $localeResolver,
    ) {
    }

    #[Route('/reset-password', name: 'app_forgot_password_request')]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();
            $this->processSendingPasswordResetEmail($data['email']);

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('@User/reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/reset-password/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession();

        if (!$resetToken instanceof ResetPasswordToken) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('@User/reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password')]
    public function reset(Request $request, ?string $token = null): Response
    {
        if (null !== $token) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->addFlash('danger', new TranslatableMessage('flash.reset_password.validation_failed', domain: 'user'));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        if (!$user instanceof User) {
            throw new \LogicException('Unsupported user returned by reset password helper.');
        }

        if (!$user->isEnabled()) {
            $this->addFlash('danger', new TranslatableMessage('flash.reset_password.validation_failed', domain: 'user'));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            $plainPassword = $form->get('plainPassword')->getData();
            if (!\is_string($plainPassword) || '' === $plainPassword) {
                throw new \LogicException('Reset password form did not provide a password.');
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->setCredentialsExpired(false);
            $this->auditContext->beginIntent('user.password_reset_completed', []);
            try {
                $this->entityManager->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            $this->cleanSessionAfterReset();
            $this->addFlash('success', new TranslatableMessage('flash.reset_password.changed', domain: 'user'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('@User/reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($emailFormData))]);
        if (!$user instanceof User || !$user->isVerified() || !$user->isEnabled()) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            return $this->redirectToRoute('app_check_email');
        }

        $this->transactionalMailer->sendPasswordResetEmail(
            (string) $user->getEmail(),
            $resetToken,
            $this->localeResolver->resolveForUser($user),
        );

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
