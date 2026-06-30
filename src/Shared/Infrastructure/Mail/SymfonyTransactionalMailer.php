<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Shared\Application\Locale\LocaleResolver;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use Psr\Log\LoggerInterface;
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
        private FeedbackRecipientEmailResolver $feedbackRecipientResolver,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private LocaleResolver $localeResolver,
        private LoggerInterface $logger,
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
            ->subject($this->translator->trans('email.verify.title', [], null, $locale))
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
            ->subject($this->translator->trans('email.reset_password.title', [], null, $locale))
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

    #[\Override]
    public function sendAdminFeedbackEmail(
        Feedback $feedback,
        FeedbackCategory $category,
        string $contextJsonPreview,
    ): void {
        $recipientsByLocale = $this->localeResolver->groupEmailsByLocale(
            $this->feedbackRecipientResolver->resolveRecipientUsers(),
        );
        if ([] === $recipientsByLocale) {
            $this->logger->info('feedback.admin_mail_skipped', [
                'reason' => 'no_feedback_recipients',
                'feedback_id' => $feedback->getId(),
            ]);

            return;
        }

        foreach ($recipientsByLocale as $locale => $recipients) {
            $email = $this->createTemplatedEmail($locale)
                ->to(...$recipients)
                ->subject(sprintf(
                    '[%s] %s (%s)',
                    $this->mailConfig->appName,
                    $this->translator->trans('feedback.email.title', [], null, $locale),
                    $category->value,
                ))
                ->htmlTemplate('@Feedback/email/admin_feedback_notification.html.twig')
                ->context([
                    'feedback' => $feedback,
                    'categoryLabel' => $category->value,
                    'contextJson' => $contextJsonPreview,
                ]);

            $this->mailer->send($email);
        }
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
