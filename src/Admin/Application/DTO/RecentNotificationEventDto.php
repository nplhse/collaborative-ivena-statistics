<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO;

/**
 * One event of the admin dashboard card "Recent notifications" (#1137), with the context an admin needs to decide
 * whether to act. Labels are translated in the template (`ops.notifications.event.<intent>`).
 */
final readonly class RecentNotificationEventDto
{
    public function __construct(
        public string $intent,
        public \DateTimeImmutable $occurredAt,
        /** Links the event to its request in the audit timeline. */
        public string $requestId,
        /** Import name, username or hospital name; null when the record no longer exists. */
        public ?string $subject,
        /** Id of the import, user or hospital, shown when the subject is gone. */
        public ?string $subjectId,
        /** Hospital of a failed import. */
        public ?string $hospital = null,
        /** `ImportRunReasonCode` value of a failed import; null for free-text reasons of older runs. */
        public ?string $reasonCode = null,
        /** First day of the reporting month of a reminder. */
        public ?\DateTimeImmutable $reportingPeriod = null,
    ) {
    }
}
