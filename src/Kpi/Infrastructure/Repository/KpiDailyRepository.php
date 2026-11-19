<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Repository;

use App\Kpi\Domain\Entity\KpiDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * @extends ServiceEntityRepository<KpiDaily>
 */
final class KpiDailyRepository extends ServiceEntityRepository
{
    private const string TIMEZONE = 'Europe/Berlin';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, KpiDaily::class);
    }

    public function deleteByDate(\DateTimeImmutable $date): void
    {
        $this->createQueryBuilder('k')
            ->delete()
            ->where('k.date = :date')
            ->setParameter('date', $date->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    public function countActiveHospitalsLast30Days(): int
    {
        $from = $this->dateDaysAgo(30);

        return (int) $this->createQueryBuilder('k')
            ->select('COUNT(DISTINCT IDENTITY(k.hospital))')
            ->where('k.hospital IS NOT NULL')
            ->andWhere('k.date >= :from')
            ->andWhere('k.successfulImportsCount > 0')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Totals of the global rows over a period of Berlin calendar days (`$toExclusive` is the first day after it);
     * days without a row count as zero. Used by the admin KPI row.
     *
     * @return array{importsCount: int, recordsTotal: int, recordsRejected: int}
     */
    public function globalTotalsInRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(k.imports_count), 0) AS imports_count,
                    COALESCE(SUM(k.records_total), 0) AS records_total,
                    COALESCE(SUM(k.records_rejected), 0) AS records_rejected
                FROM kpi_daily k
                WHERE k.hospital_id IS NULL
                  AND k.date >= :from
                  AND k.date < :to
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $toExclusive->format('Y-m-d')],
        );

        return [
            'importsCount' => (int) ($row['imports_count'] ?? 0),
            'recordsTotal' => (int) ($row['records_total'] ?? 0),
            'recordsRejected' => (int) ($row['records_rejected'] ?? 0),
        ];
    }

    /**
     * Hospitals that participate today and had at least one successful import in the period.
     */
    public function countActiveParticipatingHospitalsInRange(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT k.hospital_id)
                FROM kpi_daily k
                INNER JOIN hospital h ON h.id = k.hospital_id
                WHERE h.is_participating = true
                  AND k.successful_imports_count > 0
                  AND k.date >= :from
                  AND k.date < :to
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $toExclusive->format('Y-m-d')],
        );
    }

    public function countParticipatingHospitals(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM hospital WHERE is_participating = true');
    }

    /**
     * Global rows from `$from` up to `$toExclusive`, ordered by date; days without a row are missing.
     *
     * @return list<array{date: \DateTimeImmutable, recordsRejected: int, recordsTotal: int}>
     */
    public function getDailySeriesGlobal(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        /** @var list<array{date: \DateTimeImmutable, recordsRejected: int|string, recordsTotal: int|string}> $rows */
        $rows = $this->createQueryBuilder('k')
            ->select(
                'k.date AS date',
                'k.recordsRejected AS recordsRejected',
                'k.recordsTotal AS recordsTotal',
            )
            ->where('k.hospital IS NULL')
            ->andWhere('k.date >= :from')
            ->andWhere('k.date < :toExclusive')
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('toExclusive', $toExclusive->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('k.date', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'recordsRejected' => (int) $row['recordsRejected'],
            'recordsTotal' => (int) $row['recordsTotal'],
        ], $rows);
    }

    public static function calculateRejectionRate(int $rejected, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(((float) $rejected / (float) $total) * 100.0, 2);
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
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('toExclusive', $toExclusive->setTime(0, 0), Types::DATE_IMMUTABLE)
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

        return $this->clock->now()->setTimezone($tz)->modify('today')
            ->modify(sprintf('-%d days', $days - 1))
            ->setTime(0, 0);
    }
}
