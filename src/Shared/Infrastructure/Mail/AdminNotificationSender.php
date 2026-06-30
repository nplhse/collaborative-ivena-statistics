<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationSenderInterface;
use App\Shared\Application\Notification\AdminNotificationType;
use App\User\Infrastructure\Security\NotificationRecipientEmailResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @psalm-suppress UnusedClass */
#[AsAlias(AdminNotificationSenderInterface::class)]
final readonly class AdminNotificationSender implements AdminNotificationSenderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private MailerInterface $mailer,
        private MailConfig $mailConfig,
        private NotificationRecipientEmailResolver $notificationRecipientResolver,
        private TranslatorInterface $translator,
        private LocaleResolver $localeResolver,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function send(AdminNotification $notification): void
    {
        $recipientsByLocale = $this->localeResolver->groupEmailsByLocale(
            $this->notificationRecipientResolver->resolveRecipientUsers(),
        );
        if ([] === $recipientsByLocale) {
            $this->logger->info('admin_notification.skipped', [
                'reason' => 'no_notification_recipients',
                'type' => $notification->type->value,
                'reference_id' => $notification->referenceId,
            ]);

            return;
        }

        foreach ($recipientsByLocale as $locale => $recipients) {
            $email = $this->createTemplatedEmail($locale)
                ->to(...$recipients)
                ->subject($this->buildSubject($notification->type, $locale))
                ->htmlTemplate($this->resolveTemplate($notification->type))
                ->context($notification->templateContext);

            $this->mailer->send($email);
        }
    }

    private function buildSubject(AdminNotificationType $type, string $locale): string
    {
        $label = $this->translator->trans($type->subjectTranslationKey(), [], null, $locale);

        return sprintf('[%s] %s', $this->mailConfig->appName, $label);
    }

    private function resolveTemplate(AdminNotificationType $type): string
    {
        return match ($type) {
            AdminNotificationType::UserRegistered => '@Shared/email/admin_notification/user_registered.html.twig',
            AdminNotificationType::ImportFailed => '@Shared/email/admin_notification/import_failed.html.twig',
        };
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
