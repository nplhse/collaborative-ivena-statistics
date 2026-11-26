<?php

declare(strict_types=1);

namespace App\Feedback\Domain\Enum;

/**
 * Whether a feedback entry is treated as regular feedback or kept in quarantine as suspected spam.
 * Independent of the workflow {@see FeedbackStatus}.
 */
enum FeedbackClassification: string
{
    case NORMAL = 'normal';
    case QUARANTINED = 'quarantined';
}
