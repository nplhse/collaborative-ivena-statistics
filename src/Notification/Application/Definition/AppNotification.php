<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

use Symfony\Component\Notifier\Notification\Notification;

/**
 * Base class for every notification type. Subclasses carry the {@see NotificationDefinition} attribute
 * and are collected into the {@see NotificationRegistry} at container compile time.
 */
abstract class AppNotification extends Notification
{
}
