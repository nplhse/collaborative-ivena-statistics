<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Application\Dto\MonthlyReminderContent;
use App\Shared\Infrastructure\Mail\MailConfig;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReminderMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function send(string $recipientEmail, MonthlyReminderContent $content, string $locale): void
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailConfig->fromEmail, $this->mailConfig->fromName))
            ->to($recipientEmail)
            ->subject($this->translator->trans('monthly_reminder.subject', [
                'hospital' => $content->hospitalName,
                'period' => $content->reportingPeriodLabel,
            ], 'engagement', $locale))
            ->locale($locale)
            ->htmlTemplate('@Engagement/email/monthly_submission_reminder.html.twig')
            ->context([
                'content' => $content,
                'app_title' => $this->mailConfig->appName,
            ]);

        $replyTo = $this->mailConfig->replyTo();
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        $this->mailer->send($email);

        $this->logger->info('Monthly submission reminder sent.', [
            'recipient' => $recipientEmail,
            'hospital' => $content->hospitalName,
        ]);
    }
}
