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
        $this->getEntityManager()->getConnection()->executeStatement(
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
    }

    public function save(MonthlyReminderDispatch $dispatch): void
    {
        $this->getEntityManager()->persist($dispatch);
        $this->getEntityManager()->flush();
    }
}
