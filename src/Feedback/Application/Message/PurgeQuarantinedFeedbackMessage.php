<?php

declare(strict_types=1);

namespace App\Feedback\Application\Message;

/**
 * Triggers the daily deletion of quarantined feedback past its retention (issue #964).
 */
final readonly class PurgeQuarantinedFeedbackMessage
{
}
