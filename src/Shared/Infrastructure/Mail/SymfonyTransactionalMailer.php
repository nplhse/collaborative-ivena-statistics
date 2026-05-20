<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/** @psalm-suppress UnusedClass Wired via services.yaml alias to TransactionalMailer. */
final readonly class SymfonyTransactionalMailer implements TransactionalMailer
{
    private const string SUBJECT_VERIFICATION = 'Please confirm your email address';

    private const string SUBJECT_PASSWORD_RESET = 'Your password reset request';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private FeedbackRecipientEmailResolver $feedbackRecipientResolver,
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
    ): void {
        $email = $this->createTemplatedEmail()
            ->to($recipientEmail)
            ->subject(self::SUBJECT_VERIFICATION)
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
    ): void {
        $email = $this->createTemplatedEmail()
            ->to($recipientEmail)
            ->subject(self::SUBJECT_PASSWORD_RESET)
            ->htmlTemplate('@User/reset_password/email.html.twig')
            ->context([
                'resetToken' => $resetToken,
            ]);

        $this->mailer->send($email);
    }

    #[\Override]
    public function sendAdminFeedbackEmail(
        Feedback $feedback,
        FeedbackCategory $category,
        string $contextJsonPreview,
    ): void {
        $recipients = $this->feedbackRecipientResolver->resolveRecipientEmails();
        if ([] === $recipients) {
            $this->logger->info('feedback.admin_mail_skipped', [
                'reason' => 'no_feedback_recipients',
                'feedback_id' => $feedback->getId(),
            ]);

            return;
        }

        $email = $this->createTemplatedEmail()
            ->to(...$recipients)
            ->subject(sprintf('[%s] Feedback (%s)', $this->mailConfig->appName, $category->value))
            ->htmlTemplate('@Feedback/email/admin_feedback_notification.html.twig')
            ->context([
                'feedback' => $feedback,
                'categoryLabel' => $category->value,
                'contextJson' => $contextJsonPreview,
            ]);

        $this->mailer->send($email);
    }

    private function createTemplatedEmail(): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailConfig->fromEmail, $this->mailConfig->fromName));

        $replyTo = $this->mailConfig->replyTo();
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }
}
