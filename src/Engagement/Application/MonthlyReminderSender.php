<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Symfony\Component\Lock\LockFactory;

final readonly class MonthlyReminderSender
{
    private const int MANUAL_LOCK_TTL_SECONDS = 300;

    public function __construct(
        private MonthlyReminderContentBuilder $contentBuilder,
        private MonthlyReminderMailer $mailer,
        private MonthlyReminderPeriodResolver $periodResolver,
        private MonthlyReminderDispatchRepository $dispatchRepository,
        private LocaleResolver $localeResolver,
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
        int $bulkIndex = 0,
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

        $period = $this->periodResolver->resolve($referenceDate);
        $dispatchPeriod = sprintf('%04d-%02d', $period['uploadYear'], $period['uploadMonth']);

        if (
            MonthlyReminderTrigger::Scheduler === $trigger
            && $this->dispatchRepository->hasActiveDispatchForHospitalPeriodAndTrigger(
                (int) $hospital->getId(),
                $dispatchPeriod,
                $trigger->value,
            )
        ) {
            return ['monthly_reminder.error.already_sent_for_period'];
        }

        $ownerLocale = $this->localeResolver->resolveForUser($owner);
        $content = $this->contentBuilder->build($hospital, $referenceDate, $ownerLocale);
        $reportingMonth = $content->reportingPeriodLabel;

        $queuedAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $existingDispatch = $this->dispatchRepository->findForHospitalPeriodAndTrigger(
            (int) $hospital->getId(),
            $dispatchPeriod,
            $trigger->value,
        );

        if ($existingDispatch instanceof MonthlyReminderDispatch) {
            $existingDispatch->prepareForSend($email, $queuedAt);
            $dispatch = $existingDispatch;
        } else {
            $dispatch = new MonthlyReminderDispatch(
                $hospital,
                $dispatchPeriod,
                $trigger->value,
                $queuedAt,
                MonthlyReminderDispatchStatus::Queued,
                $email,
            );
        }

        $this->dispatchRepository->save($dispatch);

        $this->auditContext->beginIntent('hospital.reminder_sent', [
            'hospital_id' => $hospital->getId(),
            'owner_id' => $owner->getId(),
            'reporting_period' => $reportingMonth,
            'trigger' => $trigger->value,
            'owner_locale' => $ownerLocale,
            'dispatch_id' => $dispatch->getId(),
        ]);
        try {
            $this->mailer->send(
                $email,
                $content,
                $ownerLocale,
                $bulkIndex,
                $dispatch->getId(),
            );
        } catch (\Throwable $exception) {
            $dispatch->markFailed($exception->getMessage());
            $this->dispatchRepository->save($dispatch);

            throw $exception;
        } finally {
            $this->auditContext->endIntent();
        }

        return [];
    }
}
