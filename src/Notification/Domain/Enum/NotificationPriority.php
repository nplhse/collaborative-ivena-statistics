<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

use Symfony\Component\Notifier\Notification\Notification;

enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * Maps onto the importance levels of Symfony Notifier.
     */
    public function toNotifierImportance(): string
    {
        return match ($this) {
            self::Low => Notification::IMPORTANCE_LOW,
            self::Normal => Notification::IMPORTANCE_MEDIUM,
            self::High => Notification::IMPORTANCE_HIGH,
            self::Urgent => Notification::IMPORTANCE_URGENT,
        };
    }
}
