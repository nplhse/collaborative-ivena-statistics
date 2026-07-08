<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Repository;

use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function save(MonthlyReminderDispatch $dispatch): void
    {
        $this->getEntityManager()->persist($dispatch);
        $this->getEntityManager()->flush();
    }
}
