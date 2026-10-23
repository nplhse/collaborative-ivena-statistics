<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Preference\RecipientChannelResolver;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Decides whether a hospital's monthly reminder is due and records it in `monthly_reminder_dispatch`;
 * the reminder itself goes out through the notification system (ADR 016):
 *
 * - scheduled and CLI reminders use `engagement.monthly_reminder`, which owners can switch off or
 *   extend to the in-app inbox on the notification settings page;
 * - an administrator's reminder uses `engagement.monthly_reminder_manual` with a mandatory email,
 *   deduplicated per admin action (request id), so a double click sends one email.
 */
final readonly class MonthlyReminderSender
{
    private const int MANUAL_LOCK_TTL_SECONDS = 300;

    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private MonthlyReminderPeriodResolver $periodResolver,
        private MonthlyReminderDispatchRepository $dispatchRepository,
        private NotificationPublisherInterface $publisher,
        private RecipientChannelResolver $channelResolver,
        private LocaleResolver $localeResolver,
        private AuditContext $auditContext,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @param string|null $requestId identifies one admin action; repeating it (double click, retry) sends nothing new.
     *                               Only used for the admin trigger; a new id is generated when it is missing.
     *
     * @return list<string> validation errors; empty list means queued
     */
    public function sendForHospital(
        Hospital $hospital,
        MonthlyReminderTrigger $trigger,
        ?\DateTimeImmutable $referenceDate = null,
        ?string $requestId = null,
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

        if (MonthlyReminderTrigger::Admin !== $trigger && [] === $this->scheduledChannels($owner)) {
            return ['monthly_reminder.error.opted_out'];
        }

        $lock = null;
        if (MonthlyReminderTrigger::Admin === $trigger) {
            $hospitalId = (int) $hospital->getId();
            $since = new \DateTimeImmutable(sprintf('-%d seconds', self::MANUAL_LOCK_TTL_SECONDS), new \DateTimeZone(self::TIMEZONE));
            if ($this->dispatchRepository->hasRecentDispatchForHospitalAndTrigger($hospitalId, $trigger->value, $since)) {
                return ['monthly_reminder.error.already_sent_recently'];
            }

            $lock = $this->lockFactory->createLock(sprintf('reminder-manual-%d', $hospitalId), self::MANUAL_LOCK_TTL_SECONDS);
            if (!$lock->acquire()) {
                return ['monthly_reminder.error.already_sent_recently'];
            }
        }

        try {
            return $this->sendForHospitalUnlocked($hospital, $owner, $trigger, $referenceDate, $requestId);
        } finally {
            if ($lock instanceof LockInterface && $lock->isAcquired()) {
                $lock->release();
            }
        }
    }

    /**
     * @return list<string> validation errors; empty list means queued
     */
    private function sendForHospitalUnlocked(
        Hospital $hospital,
        User $owner,
        MonthlyReminderTrigger $trigger,
        ?\DateTimeImmutable $referenceDate,
        ?string $requestId,
    ): array {
        $hospitalId = (int) $hospital->getId();
        $referenceDate ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $period = $this->periodResolver->resolve($referenceDate);
        $dispatchPeriod = sprintf('%04d-%02d', $period['uploadYear'], $period['uploadMonth']);

        if (
            MonthlyReminderTrigger::Scheduler === $trigger
            && $this->dispatchRepository->hasActiveDispatchForHospitalPeriodAndTrigger($hospitalId, $dispatchPeriod, $trigger->value)
        ) {
            return ['monthly_reminder.error.already_sent_for_period'];
        }

        $email = trim((string) $owner->getEmail());
        $queuedAt = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $dispatch = $this->dispatchRepository->findForHospitalPeriodAndTrigger($hospitalId, $dispatchPeriod, $trigger->value);
        $isRetry = $dispatch instanceof MonthlyReminderDispatch;

        if ($dispatch instanceof MonthlyReminderDispatch) {
            $dispatch->prepareForSend($email, $queuedAt);
        } else {
            $dispatch = new MonthlyReminderDispatch($hospital, $dispatchPeriod, $trigger->value, $queuedAt, MonthlyReminderDispatchStatus::Queued, $email);
        }

        $this->dispatchRepository->save($dispatch);

        $isManual = MonthlyReminderTrigger::Admin === $trigger;
        $this->auditContext->beginIntent('hospital.reminder_sent', [
            'hospital_id' => $hospitalId,
            'owner_id' => $owner->getId(),
            'reporting_period' => sprintf('%04d-%02d', $period['reportingYear'], $period['reportingMonth']),
            'trigger' => $trigger->value,
            'owner_locale' => $this->localeResolver->resolveForUser($owner),
            'dispatch_id' => $dispatch->getId(),
        ]);
        try {
            $this->publisher->publish(new NotificationRequest(
                type: $isManual ? MonthlyReminderNotificationType::MANUAL : MonthlyReminderNotificationType::SCHEDULED,
                recipientUserIds: [(int) $owner->getId()],
                deduplicationKey: $this->deduplicationKey($hospitalId, $dispatchPeriod, $trigger, $isRetry, $queuedAt, $requestId),
                parameters: [
                    'hospitalId' => $hospitalId,
                    'hospitalName' => (string) $hospital->getName(),
                    'referenceDate' => $referenceDate->format('Y-m-d'),
                    // Noon avoids a different month after time zone conversion when the month name is rendered.
                    'period' => $period['reportingMonthStart']->setTime(12, 0)->getTimestamp(),
                    'dispatchId' => $dispatch->getId(),
                ],
                action: new NotificationAction('app_import_new'),
                scopeReferenceId: $hospitalId,
                metadata: ['reference' => sprintf('monthly_reminder_dispatch:%d', (int) $dispatch->getId())],
            ));
        } finally {
            $this->auditContext->endIntent();
        }

        if (!$isManual && !\in_array(NotificationChannel::Email, $this->scheduledChannels($owner), true)) {
            // In-app only: there is no email whose events would complete the dispatch.
            $dispatch->markSent($queuedAt);
            $this->dispatchRepository->save($dispatch);
        }

        return [];
    }

    /**
     * Channels of the scheduled reminder for the owner; empty when the owner switched it off.
     *
     * @return list<NotificationChannel>
     */
    private function scheduledChannels(User $owner): array
    {
        $channels = $this->channelResolver->resolve((int) $owner->getId(), MonthlyReminderNotificationType::SCHEDULED);

        return array_values(array_filter([NotificationChannel::Email, NotificationChannel::InApp], $channels->has(...)));
    }

    /**
     * Scheduled reminders: one per hospital and period (ADR 016); a retry after a failed attempt is a new
     * publication. CLI sends are manual tests and always new. Admin sends: one per admin action.
     */
    private function deduplicationKey(int $hospitalId, string $period, MonthlyReminderTrigger $trigger, bool $isRetry, \DateTimeImmutable $queuedAt, ?string $requestId): string
    {
        return match ($trigger) {
            MonthlyReminderTrigger::Admin => sprintf('%s:%d:%s', MonthlyReminderNotificationType::MANUAL, $hospitalId, $requestId ?? Uuid::v4()->toRfc4122()),
            MonthlyReminderTrigger::Cli => sprintf('%s:%d:%s:cli:%s', MonthlyReminderNotificationType::SCHEDULED, $hospitalId, $period, $queuedAt->format('YmdHisu')),
            MonthlyReminderTrigger::Scheduler => $isRetry
                ? sprintf('%s:%d:%s:retry:%s', MonthlyReminderNotificationType::SCHEDULED, $hospitalId, $period, $queuedAt->format('YmdHis'))
                : sprintf('%s:%d:%s', MonthlyReminderNotificationType::SCHEDULED, $hospitalId, $period),
        };
    }
}
