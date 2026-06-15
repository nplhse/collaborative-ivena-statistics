<?php

declare(strict_types=1);

namespace App\Shared\Application\Notification;

interface AdminNotificationSenderInterface
{
    public function send(AdminNotification $notification): void;
}
