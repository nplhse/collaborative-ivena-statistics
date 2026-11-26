<?php

declare(strict_types=1);

namespace App\Feedback\Application\Contract;

/**
 * The weekly summary of newly quarantined feedback (issue #964).
 */
interface FeedbackQuarantineSummaryInterface
{
    /** Number of quarantined entries the next summary would report. */
    public function countPending(): int;

    /** Sends the summary and returns the number of entries it reported (0: nothing was sent). */
    public function send(): int;
}
