<?php

declare(strict_types=1);

namespace App\Feedback\Application\Contract;

/**
 * Deletes quarantined feedback after `app.feedback.quarantine.retention_days` (issue #964).
 */
interface FeedbackQuarantinePurgeInterface
{
    /** Quarantined entries whose retention has ended. */
    public function countExpired(): int;

    /** Deletes the quarantined entries whose retention has ended and returns how many. */
    public function purge(): int;
}
