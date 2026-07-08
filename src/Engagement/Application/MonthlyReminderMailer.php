<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Application\Dto\MonthlyReminderContent;
use App\Shared\Infrastructure\Mail\MailConfig;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReminderMailer
{
    public const string DISPATCH_ID_HEADER = 'X-COIS-Monthly-Reminder-Dispatch-Id';

    public function __construct(
        private MailerInterface $mailer,
        private MessageBusInterface $messageBus,
        private MailConfig $mailConfig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire('%app.mailer_bulk_delay_ms%')]
        private int $bulkDelayMs,
    ) {
    }

    public function send(
        string $recipientEmail,
        MonthlyReminderContent $content,
        string $locale,
        int $bulkIndex = 0,
        ?int $dispatchId = null,
    ): void {
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

        if (null !== $dispatchId) {
            $email->getHeaders()->addTextHeader(self::DISPATCH_ID_HEADER, (string) $dispatchId);
        }

        $delayMs = $bulkIndex * $this->bulkDelayMs;
        if ($delayMs > 0) {
            $this->messageBus->dispatch(new SendEmailMessage($email), [new DelayStamp($delayMs)]);
        } else {
            $this->mailer->send($email);
        }

        $this->logger->info('Monthly submission reminder sent.', [
            'recipient' => $recipientEmail,
            'hospital' => $content->hospitalName,
            'bulk_index' => $bulkIndex,
            'delay_ms' => $delayMs,
            'dispatch_id' => $dispatchId,
        ]);
    }
}
