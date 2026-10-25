<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Repository;

use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonthlyReminderDispatch>
 */
final class MonthlyReminderDispatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonthlyReminderDispatch::class);
    }

    public function existsForHospitalPeriodAndTrigger(
        int $hospitalId,
        string $reportingPeriod,
        string $trigger,
    ): bool {
        return $this->hasActiveDispatchForHospitalPeriodAndTrigger($hospitalId, $reportingPeriod, $trigger);
    }

    public function hasActiveDispatchForHospitalPeriodAndTrigger(
        int $hospitalId,
        string $reportingPeriod,
        string $trigger,
    ): bool {
        return null !== $this->createQueryBuilder('dispatch')
            ->andWhere('dispatch.hospital = :hospitalId')
            ->andWhere('dispatch.reportingPeriod = :reportingPeriod')
            ->andWhere('dispatch.trigger = :trigger')
            ->andWhere('dispatch.status IN (:statuses)')
            ->setParameter('hospitalId', $hospitalId)
            ->setParameter('reportingPeriod', $reportingPeriod)
            ->setParameter('trigger', $trigger)
            ->setParameter('statuses', [
                MonthlyReminderDispatchStatus::Queued,
                MonthlyReminderDispatchStatus::Sent,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForHospitalPeriodAndTrigger(
        int $hospitalId,
        string $reportingPeriod,
        string $trigger,
    ): ?MonthlyReminderDispatch {
        return $this->findOneBy([
            'hospital' => $hospitalId,
            'reportingPeriod' => $reportingPeriod,
            'trigger' => $trigger,
        ]);
    }

    public function hasRecentDispatchForHospitalAndTrigger(
        int $hospitalId,
        string $trigger,
        \DateTimeImmutable $since,
    ): bool {
        return null !== $this->createQueryBuilder('dispatch')
            ->andWhere('dispatch.hospital = :hospitalId')
            ->andWhere('dispatch.trigger = :trigger')
            ->andWhere('dispatch.sentAt >= :since')
            ->setParameter('hospitalId', $hospitalId)
            ->setParameter('trigger', $trigger)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Marks the dispatch as failed when it is still queued for exactly this attempt of this hospital. One
     * statement on the default connection, so it joins an open transaction of the caller.
     */
    public function markAttemptFailed(int $dispatchId, int $hospitalId, ?string $attemptId, string $failureReason): void
    {
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE monthly_reminder_dispatch
                SET status = :failed, failure_reason = :reason
                WHERE id = :id AND hospital_id = :hospital AND status = :queued
                  AND attempt_id IS NOT DISTINCT FROM CAST(:attempt AS VARCHAR)
                SQL,
            [
                'failed' => MonthlyReminderDispatchStatus::Failed->value,
                'queued' => MonthlyReminderDispatchStatus::Queued->value,
                'reason' => $failureReason,
                'id' => $dispatchId,
                'hospital' => $hospitalId,
                'attempt' => $attemptId,
            ],
        );

        $this->refreshIfLoaded($dispatchId, $updated);
    }

    /**
     * Marks the dispatch as sent when it is still queued for exactly this attempt.
     */
    public function markAttemptSent(int $dispatchId, ?string $attemptId, \DateTimeImmutable $deliveredAt): void
    {
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE monthly_reminder_dispatch
                SET status = :sent, delivered_at = :delivered_at, failure_reason = NULL
                WHERE id = :id AND status = :queued
                  AND attempt_id IS NOT DISTINCT FROM CAST(:attempt AS VARCHAR)
                SQL,
            [
                'sent' => MonthlyReminderDispatchStatus::Sent->value,
                'queued' => MonthlyReminderDispatchStatus::Queued->value,
                'delivered_at' => $deliveredAt,
                'id' => $dispatchId,
                'attempt' => $attemptId,
            ],
            ['delivered_at' => Types::DATETIME_IMMUTABLE],
        );

        $this->refreshIfLoaded($dispatchId, $updated);
    }

    /**
     * The updates above bypass the entity manager; a dispatch already loaded in this request (e.g. by the
     * sender when delivery runs synchronously) is reloaded, so it does not show or later write a stale state.
     */
    private function refreshIfLoaded(int $dispatchId, int|string $updated): void
    {
        if (0 === (int) $updated) {
            return;
        }

        $loaded = $this->getEntityManager()->getUnitOfWork()->tryGetById($dispatchId, MonthlyReminderDispatch::class);
        if ($loaded instanceof MonthlyReminderDispatch) {
            $this->getEntityManager()->refresh($loaded);
        }
    }

    public function save(MonthlyReminderDispatch $dispatch): void
    {
        $this->getEntityManager()->persist($dispatch);
        $this->getEntityManager()->flush();
    }
}
