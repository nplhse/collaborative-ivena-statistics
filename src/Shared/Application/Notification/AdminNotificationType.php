<?php

declare(strict_types=1);

namespace App\Shared\Application\Notification;

enum AdminNotificationType: string
{
    case UserRegistered = 'user_registered';
    case ImportFailed = 'import_failed';

    public function subjectTranslationKey(): string
    {
        return match ($this) {
            self::UserRegistered => 'admin_notification.email.subject.user_registered',
            self::ImportFailed => 'admin_notification.email.subject.import_failed',
        };
    }
}
