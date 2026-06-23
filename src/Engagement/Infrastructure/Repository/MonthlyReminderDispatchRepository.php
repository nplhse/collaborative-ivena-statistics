<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Repository;

use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
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
        return null !== $this->findOneBy([
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
