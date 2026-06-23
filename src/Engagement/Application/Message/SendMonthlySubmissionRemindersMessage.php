<?php

declare(strict_types=1);

namespace App\Engagement\Application\Message;

/**
 * Triggers monthly data submission reminder emails for all participating hospitals with an owner.
 */
final readonly class SendMonthlySubmissionRemindersMessage
{
    public function __construct(
        public ?\DateTimeImmutable $referenceDate = null,
    ) {
    }
}
