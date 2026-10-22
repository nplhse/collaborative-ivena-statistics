<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

final class UnknownNotificationTypeException extends \InvalidArgumentException
{
    public static function forType(string $type): self
    {
        return new self(sprintf('Notification type "%s" is not registered.', $type));
    }
}
