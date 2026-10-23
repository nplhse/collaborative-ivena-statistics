<?php

declare(strict_types=1);

namespace App\Notification\Domain\Enum;

/**
 * Why a delivery failed for good. Stored instead of raw error messages (ADR 016, data minimization).
 */
enum NotificationDeliveryFailureReason: string
{
    /** The recipient has no usable address any more, or the account is disabled or gone. */
    case RecipientUnavailable = 'recipient_unavailable';
    /** The channel's transport gave up (e.g. SMTP errors after all retries). */
    case TransportFailed = 'transport_failed';
    /** The delivery job itself failed after all retries. */
    case DeliveryJobFailed = 'delivery_job_failed';
}
