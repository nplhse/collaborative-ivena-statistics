<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Symfony\Component\Lock\LockFactory;

final readonly class MonthlyReminderSender
{
    private const int MANUAL_LOCK_TTL_SECONDS = 300;

    public function __construct(
        private MonthlyReminderContentBuilder $contentBuilder,
        private MonthlyReminderMailer $mailer,
        private AuditContext $auditContext,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @return list<string> validation errors; empty list means sent
     */
    public function sendForHospital(
        Hospital $hospital,
        MonthlyReminderTrigger $trigger,
        ?\DateTimeImmutable $referenceDate = null,
    ): array {
        if (!$hospital->isParticipating()) {
            return ['monthly_reminder.error.not_participating'];
        }

        $owner = $hospital->getOwner();
        if (!$owner instanceof User) {
            return ['monthly_reminder.error.no_owner'];
        }

        $email = trim((string) $owner->getEmail());
        if ('' === $email) {
            return ['monthly_reminder.error.no_email'];
        }

        if (!$owner->isVerified()) {
            return ['monthly_reminder.error.email_not_verified'];
        }

        if (
            MonthlyReminderTrigger::Admin !== $trigger
            && !$owner->receivesMonthlySubmissionReminder()
        ) {
            return ['monthly_reminder.error.opted_out'];
        }

        if (MonthlyReminderTrigger::Admin === $trigger) {
            $lock = $this->lockFactory->createLock(
                sprintf('reminder-manual-%d', (int) $hospital->getId()),
                self::MANUAL_LOCK_TTL_SECONDS,
            );
            if (!$lock->acquire()) {
                return ['monthly_reminder.error.already_sent_recently'];
            }
        }

        $content = $this->contentBuilder->build($hospital, $referenceDate);
        $reportingMonth = $content->reportingPeriodLabel;

        $this->auditContext->beginIntent('hospital.reminder_sent', [
            'hospital_id' => $hospital->getId(),
            'owner_id' => $owner->getId(),
            'reporting_period' => $reportingMonth,
            'trigger' => $trigger->value,
        ]);
        try {
            $this->mailer->send($email, $content);
        } finally {
            $this->auditContext->endIntent();
        }

        return [];
    }
}
