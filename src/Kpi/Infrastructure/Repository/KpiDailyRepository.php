<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Repository;

use App\Kpi\Domain\Entity\KpiDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KpiDaily>
 */
final class KpiDailyRepository extends ServiceEntityRepository
{
    private const string TIMEZONE = 'Europe/Berlin';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KpiDaily::class);
    }

    public function deleteByDate(\DateTimeImmutable $date): void
    {
        $this->createQueryBuilder('k')
            ->delete()
            ->where('k.date = :date')
            ->setParameter('date', $date->setTime(0, 0))
            ->getQuery()
            ->execute();
    }

    /**
     * @return array{
     *     importsCount: int,
     *     recordsProcessed: int,
     *     recordsRejected: int,
     *     recordsTotal: int,
     * }
     */
    public function sumLast30DaysGlobal(): array
    {
        $from = $this->dateDaysAgo(30);

        /** @var array{importsCount: int|string|null, recordsProcessed: int|string|null, recordsRejected: int|string|null, recordsTotal: int|string|null}|null $row */
        $row = $this->createQueryBuilder('k')
            ->select(
                'COALESCE(SUM(k.importsCount), 0) AS importsCount',
                'COALESCE(SUM(k.recordsProcessed), 0) AS recordsProcessed',
                'COALESCE(SUM(k.recordsRejected), 0) AS recordsRejected',
                'COALESCE(SUM(k.recordsTotal), 0) AS recordsTotal',
            )
            ->where('k.hospital IS NULL')
            ->andWhere('k.date >= :from')
            ->setParameter('from', $from)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'importsCount' => (int) ($row['importsCount'] ?? 0),
            'recordsProcessed' => (int) ($row['recordsProcessed'] ?? 0),
            'recordsRejected' => (int) ($row['recordsRejected'] ?? 0),
            'recordsTotal' => (int) ($row['recordsTotal'] ?? 0),
        ];
    }

    public function countActiveHospitalsLast30Days(): int
    {
        $from = $this->dateDaysAgo(30);

        return (int) $this->createQueryBuilder('k')
            ->select('COUNT(DISTINCT IDENTITY(k.hospital))')
            ->where('k.hospital IS NOT NULL')
            ->andWhere('k.date >= :from')
            ->andWhere('k.successfulImportsCount > 0')
            ->setParameter('from', $from)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{date: \DateTimeImmutable, recordsProcessed: int, recordsRejected: int, recordsTotal: int}>
     */
    public function getDailySeriesLast30DaysGlobal(): array
    {
        $from = $this->dateDaysAgo(30);

        /** @var list<array{date: \DateTimeImmutable, recordsProcessed: int|string, recordsRejected: int|string, recordsTotal: int|string}> $rows */
        $rows = $this->createQueryBuilder('k')
            ->select(
                'k.date AS date',
                'k.recordsProcessed AS recordsProcessed',
                'k.recordsRejected AS recordsRejected',
                'k.recordsTotal AS recordsTotal',
            )
            ->where('k.hospital IS NULL')
            ->andWhere('k.date >= :from')
            ->setParameter('from', $from)
            ->orderBy('k.date', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'date' => $row['date'],
                'recordsProcessed' => (int) $row['recordsProcessed'],
                'recordsRejected' => (int) $row['recordsRejected'],
                'recordsTotal' => (int) $row['recordsTotal'],
            ];
        }

        return $normalized;
    }

    public static function calculateRejectionRate(int $rejected, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round($rejected / $total * 100, 2);
    }

    public function rejectionRateForHospitalInRange(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): ?float
    {
        /** @var array{recordsRejected: int|string|null, recordsTotal: int|string|null}|null $row */
        $row = $this->createQueryBuilder('k')
            ->select(
                'COALESCE(SUM(k.recordsRejected), 0) AS recordsRejected',
                'COALESCE(SUM(k.recordsTotal), 0) AS recordsTotal',
            )
            ->where('IDENTITY(k.hospital) = :hospitalId')
            ->andWhere('k.date >= :from')
            ->andWhere('k.date < :toExclusive')
            ->setParameter('hospitalId', $hospitalId)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('toExclusive', $toExclusive->setTime(0, 0))
            ->getQuery()
            ->getOneOrNullResult();

        if (!\is_array($row)) {
            return null;
        }

        $total = (int) ($row['recordsTotal'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        return self::calculateRejectionRate((int) ($row['recordsRejected'] ?? 0), $total);
    }

    private function dateDaysAgo(int $days): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TIMEZONE);

        return new \DateTimeImmutable('today', $tz)
            ->modify(sprintf('-%d days', $days - 1))
            ->setTime(0, 0);
    }
}
