<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Delivery\NotificationEmailContent;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets the owning context give a notification type its own email, e.g. the participation welcome mail.
 * Types without a customizer use the generic notification email (title, message, link).
 */
#[AutoconfigureTag(self::TAG)]
interface NotificationEmailCustomizerInterface
{
    public const string TAG = 'app.notification.email_customizer';

    public function supports(string $type): bool;

    /**
     * Adjusts subject, template and context. Runs in the recipient's locale.
     */
    public function customize(NotificationEmailContent $content, User $recipient): void;
}
