<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/** @psalm-suppress UnusedClass */
#[AsAlias(TransactionalMailer::class)]
final readonly class SymfonyTransactionalMailer implements TransactionalMailer
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $expiresAtMessageData
     */
    #[\Override]
    public function sendVerificationEmail(
        string $recipientEmail,
        string $signedUrl,
        string $expiresAtMessageKey,
        array $expiresAtMessageData,
        string $homepageUrl,
        string $locale,
    ): void {
        $email = $this->createTemplatedEmail($locale)
            ->to($recipientEmail)
            ->subject($this->translator->trans('email.verify.title', [], 'user', $locale))
            ->htmlTemplate('@User/registration/confirmation_email.html.twig')
            ->context([
                'signedUrl' => $signedUrl,
                'expiresAtMessageKey' => $expiresAtMessageKey,
                'expiresAtMessageData' => $expiresAtMessageData,
                'homepageUrl' => $homepageUrl,
            ]);

        $this->mailer->send($email);
    }

    #[\Override]
    public function sendPasswordResetEmail(
        string $recipientEmail,
        ResetPasswordToken $resetToken,
        string $locale,
    ): void {
        $email = $this->createTemplatedEmail($locale)
            ->to($recipientEmail)
            ->subject($this->translator->trans('email.reset_password.title', [], 'user', $locale))
            ->htmlTemplate('@User/reset_password/email.html.twig')
            ->context([
                'resetToken' => $resetToken,
                'resetUrl' => $this->urlGenerator->generate(
                    'app_reset_password',
                    ['token' => $resetToken->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        $this->mailer->send($email);
    }

    private function createTemplatedEmail(string $locale): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailConfig->fromEmail, $this->mailConfig->fromName))
            ->locale($locale);

        $replyTo = $this->mailConfig->replyTo();
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }
}
