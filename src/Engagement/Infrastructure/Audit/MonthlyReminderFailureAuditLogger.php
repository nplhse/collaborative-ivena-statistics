<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Audit;

use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Notification\Domain\Enum\NotificationDeliveryFailureReason;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the audit entry of a failed reminder delivery (#1173). The dispatch is failed by a guarded SQL update,
 * which the Doctrine audit subscriber does not see, so the entry is written explicitly: an `update` of the
 * dispatch with the change that update made. Call it only when that update changed the dispatch, so every failed
 * attempt is recorded once, however often its failure is announced. The failure is system activity (a worker or the
 * hourly reconciliation), so the entry has no actor.
 */
final readonly class MonthlyReminderFailureAuditLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $reportingPeriod reporting month (`Y-m`), as in `hospital.reminder_sent`
     * @param string      $reason          a `NotificationDeliveryFailureReason` value
     */
    public function log(int $dispatchId, int $hospitalId, ?string $reportingPeriod, string $reason, string $failureReason): void
    {
        $requestId = $this->auditContext->ensureRequestId(static fn (): string => Uuid::v7()->toRfc4122());

        $this->entityManager->persist(new AuditEntry(
            $this->clock->now(),
            $requestId,
            null,
            $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_CLI,
            'update',
            MonthlyReminderDispatch::class,
            (string) $dispatchId,
            [
                // Fixed by the guard of the update: only a queued dispatch fails, and a queued one has no reason.
                'status' => ['old' => MonthlyReminderDispatchStatus::Queued->value, 'new' => MonthlyReminderDispatchStatus::Failed->value],
                'failureReason' => ['old' => null, 'new' => $failureReason],
            ],
            [
                'intent' => AuditEntry::INTENT_HOSPITAL_REMINDER_FAILED,
                'intent_metadata' => [
                    'hospital_id' => $hospitalId,
                    'reporting_period' => $reportingPeriod,
                    'reason' => $reason,
                ],
                ...$this->auditContext->requestMetadata(),
            ],
            $this->auditContext->getSessionRef(),
        ));
        $this->entityManager->flush();
    }

    /**
     * The reason code stored in an entry's `intent_metadata.reason`, when it is a known one.
     */
    public static function reasonCode(mixed $reason): ?string
    {
        return \is_string($reason) ? NotificationDeliveryFailureReason::tryFrom($reason)?->value : null;
    }
}
