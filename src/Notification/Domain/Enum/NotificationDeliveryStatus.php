<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * State of one external delivery (ADR 016, "Delivery").
 */
enum NotificationDeliveryStatus: string
{
    /** The delivery job is stored but not yet handed to the channel. */
    case Pending = 'pending';
    /** Handed to the channel's queue (for email: SendEmailMessage stored). */
    case Queued = 'queued';
    /** Accepted by the channel's transport (for email: the SMTP server); not proof of arrival. */
    case Sent = 'sent';
    /** Given up for good; carries a reason code. Never restarted automatically. */
    case Failed = 'failed';
}
