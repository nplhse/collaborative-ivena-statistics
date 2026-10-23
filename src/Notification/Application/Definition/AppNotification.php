<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;

/**
 * Base class for every notification type. Subclasses carry the {@see NotificationDefinition} attribute
 * and are collected into the {@see NotificationRegistry} at container compile time.
 *
 * For an email delivery the delivery handler creates an instance of the type, attaches the email it
 * built from the stored entry and hands it to Symfony Notifier with exactly the email channel (ADR 016).
 *
 * @psalm-consistent-constructor Types are instantiated by class name with Notifier's constructor.
 */
abstract class AppNotification extends Notification implements EmailNotificationInterface
{
    private ?Email $email = null;

    public function withEmail(Email $email): static
    {
        $notification = clone $this;
        $notification->email = $email;

        return $notification;
    }

    #[\Override]
    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): ?EmailMessage
    {
        if (!$this->email instanceof Email) {
            return null;
        }

        return new EmailMessage((clone $this->email)->to($recipient->getEmail()));
    }
}
