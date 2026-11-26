<?php

declare(strict_types=1);

namespace App\Feedback\Application\Contract;

use App\Feedback\Application\FeedbackSpamHistory;

/**
 * Earlier feedback (normal and quarantined) within the history window, for the spam check.
 */
interface FeedbackSpamHistoryLookupInterface
{
    public function lookup(?string $guestEmail, ?string $messageFingerprint): FeedbackSpamHistory;
}
