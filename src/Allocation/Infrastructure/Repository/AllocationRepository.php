<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Query\AllocationBucketQuery;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Allocation>
 */
final class AllocationRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(
        ManagerRegistry $registry,
        private readonly AllocationBucketQuery $bucketQuery,
    ) {
        parent::__construct($registry, Allocation::class);
    }

    public function deleteByImport(Import $import): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('IDENTITY(a.import) = :importId')
            ->setParameter('importId', $import->getId(), Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    public function findOneForShow(int $id): ?Allocation
    {
        /** @var Allocation|null $allocation */
        $allocation = $this->createQueryBuilder('a')
            ->addSelect(
                'dispatchArea',
                'state',
                'department',
                'speciality',
                'indicationRaw',
                'indicationNormalized',
                'secondaryIndicationRaw',
                'secondaryIndicationNormalized',
                'assignment',
                'occasion',
                'secondaryTransport',
                'infection',
                'assessment',
                'hospital',
                'hospitalDispatchArea',
                'hospitalState',
            )
            ->join('a.dispatchArea', 'dispatchArea')
            ->join('a.state', 'state')
            ->join('a.department', 'department')
            ->join('a.speciality', 'speciality')
            ->join('a.indicationRaw', 'indicationRaw')
            ->leftJoin('a.indicationNormalized', 'indicationNormalized')
            ->leftJoin('a.secondaryIndicationRaw', 'secondaryIndicationRaw')
            ->leftJoin('a.secondaryIndicationNormalized', 'secondaryIndicationNormalized')
            ->join('a.assignment', 'assignment')
            ->leftJoin('a.occasion', 'occasion')
            ->leftJoin('a.secondaryTransport', 'secondaryTransport')
            ->leftJoin('a.infection', 'infection')
            ->leftJoin('a.assessment', 'assessment')
            ->join('a.hospital', 'hospital')
            ->leftJoin('hospital.dispatchArea', 'hospitalDispatchArea')
            ->leftJoin('hospital.state', 'hospitalState')
            ->where('a.id = :id')
            ->setParameter('id', $id, Types::INTEGER)
            ->getQuery()
            ->getOneOrNullResult();

        return $allocation;
    }

    public function findOneForShowByPublicId(Uuid|string $publicId): ?Allocation
    {
        $resolved = $publicId instanceof Uuid ? $publicId : Uuid::fromString($publicId);

        /** @var Allocation|null $allocation */
        $allocation = $this->createQueryBuilder('a')
            ->addSelect(
                'dispatchArea',
                'state',
                'department',
                'speciality',
                'indicationRaw',
                'indicationNormalized',
                'secondaryIndicationRaw',
                'secondaryIndicationNormalized',
                'assignment',
                'occasion',
                'secondaryTransport',
                'infection',
                'assessment',
                'hospital',
                'hospitalDispatchArea',
                'hospitalState',
            )
            ->join('a.dispatchArea', 'dispatchArea')
            ->join('a.state', 'state')
            ->join('a.department', 'department')
            ->join('a.speciality', 'speciality')
            ->join('a.indicationRaw', 'indicationRaw')
            ->leftJoin('a.indicationNormalized', 'indicationNormalized')
            ->leftJoin('a.secondaryIndicationRaw', 'secondaryIndicationRaw')
            ->leftJoin('a.secondaryIndicationNormalized', 'secondaryIndicationNormalized')
            ->join('a.assignment', 'assignment')
            ->leftJoin('a.occasion', 'occasion')
            ->leftJoin('a.secondaryTransport', 'secondaryTransport')
            ->leftJoin('a.infection', 'infection')
            ->leftJoin('a.assessment', 'assessment')
            ->join('a.hospital', 'hospital')
            ->leftJoin('hospital.dispatchArea', 'hospitalDispatchArea')
            ->leftJoin('hospital.state', 'hospitalState')
            ->where('a.publicId = :publicId')
            ->setParameter('publicId', $resolved->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult();

        return $allocation;
    }

    /**
     * @return list<array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateCountByMonthSince($from, null);
    }

    /**
     * Wie {@see countByMonthLast12Months()}, eingeschränkt auf die angegebenen Krankenhäuser.
     *
     * @param list<int> $hospitalIds
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->aggregateCountByMonthSince($from, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    private function aggregateCountByMonthSince(\DateTimeImmutable $from, ?array $hospitalIds): array
    {
        $sql = <<<'SQL'
SELECT EXTRACT(YEAR FROM created_at)::INT AS year,
       EXTRACT(MONTH FROM created_at)::INT AS month,
       COUNT(id)::INT AS count
FROM allocation
WHERE created_at >= :from
  AND created_at IS NOT NULL
SQL;
        $params = ['from' => $from];
        $types = ['from' => Types::DATETIME_IMMUTABLE];

        if (null !== $hospitalIds) {
            $sql .= ' AND hospital_id IN (:hospitalIds)';
            $params['hospitalIds'] = $hospitalIds;
            $types['hospitalIds'] = ArrayParameterType::INTEGER;
        }

        $sql .= ' GROUP BY 1, 2 ORDER BY 1 ASC, 2 ASC';

        /** @var list<array{year:numeric-string|int,month:numeric-string|int,count:numeric-string|int}> $raw */
        $raw = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);

        return $this->mapMonthCountRows($raw);
    }

    /**
     * @param list<array{year:numeric-string|int,month:numeric-string|int,count:numeric-string|int}> $raw
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    private function mapMonthCountRows(array $raw): array
    {
        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'month' => (int) $row['month'],
                'count' => (int) $row['count'],
            ],
            $raw,
        );
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBefore(\DateTimeInterface $before): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getEarliestCreatedAt(): ?\DateTimeImmutable
    {
        $qb = $this->createQueryBuilder('a')
            ->select('MIN(a.createdAt)')
            ->where('a.createdAt IS NOT NULL');

        $result = $qb->getQuery()->getSingleScalarResult();

        if (null === $result) {
            return null;
        }

        if ($result instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($result);
        }

        if ('' === (string) $result) {
            return null;
        }

        return new \DateTimeImmutable((string) $result);
    }

    public function countCreatedSince(\DateTimeInterface $from): int
    {
        return $this->countCreatedInPeriod($from, null);
    }

    public function countCreatedInPeriod(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        $this->applyCreatedAtBefore($qb, $toExclusive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function countCreatedSinceForHospitals(\DateTimeInterface $from, array $hospitalIds): int
    {
        return $this->countCreatedInPeriodForHospitals($from, null, $hospitalIds);
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function countCreatedInPeriodForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): int {
        if ([] === $hospitalIds) {
            return 0;
        }

        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :from')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('hospitalIds', $hospitalIds);
        $this->applyCreatedAtBefore($qb, $toExclusive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int> Keys M, F, X
     */
    public function countGroupedByGenderSinceForHospitals(\DateTimeInterface $from, array $hospitalIds): array
    {
        return $this->countGroupedByGenderInPeriodForHospitals($from, null, $hospitalIds);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int>
     */
    public function countGroupedByGenderInPeriodForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->select('a.gender AS gender')
            ->addSelect('COUNT(a.id) AS cnt')
            ->where('a.createdAt >= :from')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('hospitalIds', $hospitalIds)
            ->groupBy('a.gender');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $gender = $row['gender'] ?? null;
            $key = $gender instanceof AllocationGender ? $gender->value : (string) $gender;
            $out[$key] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        }

        return $out;
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, int> Keys 1, 2, 3 (AllocationUrgency)
     */
    public function countGroupedByUrgencySinceForHospitals(\DateTimeInterface $from, array $hospitalIds): array
    {
        return $this->countGroupedByUrgencyInPeriodForHospitals($from, null, $hospitalIds);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<int, int>
     */
    public function countGroupedByUrgencyInPeriodForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->select('a.urgency AS urgency')
            ->addSelect('COUNT(a.id) AS cnt')
            ->where('a.createdAt >= :from')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('hospitalIds', $hospitalIds)
            ->groupBy('a.urgency');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $urgency = $row['urgency'] ?? null;
            $key = $urgency instanceof AllocationUrgency ? $urgency->value : (int) $urgency;
            $out[$key] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        }

        return $out;
    }

    /**
     * @return array<string, int> Keys M, F, X
     */
    public function countGroupedByGenderSince(\DateTimeInterface $from): array
    {
        return $this->countGroupedByGenderInPeriod($from, null);
    }

    /**
     * @return array<string, int>
     */
    public function countGroupedByGenderInPeriod(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.gender AS gender')
            ->addSelect('COUNT(a.id) AS cnt')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->groupBy('a.gender');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $gender = $row['gender'] ?? null;
            $key = $gender instanceof AllocationGender ? $gender->value : (string) $gender;
            $out[$key] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        }

        return $out;
    }

    /**
     * @return array<int, int> Keys 1, 2, 3 (AllocationUrgency)
     */
    public function countGroupedByUrgencySince(\DateTimeInterface $from): array
    {
        return $this->countGroupedByUrgencyInPeriod($from, null);
    }

    /**
     * @return array<int, int>
     */
    public function countGroupedByUrgencyInPeriod(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.urgency AS urgency')
            ->addSelect('COUNT(a.id) AS cnt')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->groupBy('a.urgency');
        $this->applyCreatedAtBefore($qb, $toExclusive);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $urgency = $row['urgency'] ?? null;
            $key = $urgency instanceof AllocationUrgency ? $urgency->value : (int) $urgency;
            $out[$key] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        }

        return $out;
    }

    /**
     * Monatsaggregation für Charts im angegebenen Halbintervall [from, toExclusive).
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countAllocationsByMonthInRange(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }
            $key = $createdAt->format('Y-m');
            if (!isset($buckets[$key])) {
                $buckets[$key] = 0;
            }
            ++$buckets[$key];
        }

        $result = [];
        foreach ($buckets as $key => $count) {
            [$year, $month] = explode('-', $key);
            $result[] = [
                'year' => (int) $year,
                'month' => (int) $month,
                'count' => $count,
            ];
        }

        usort($result, static fn (array $a, array $b): int => [$a['year'], $a['month']] <=> [$b['year'], $b['month']]);

        return $result;
    }

    /**
     * Wie {@see countAllocationsByMonthInRange()}, eingeschränkt auf die angegebenen Krankenhäuser.
     *
     * @param list<int> $hospitalIds
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countAllocationsByMonthInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->setParameter('hospitalIds', $hospitalIds)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }
            $key = $createdAt->format('Y-m');
            if (!isset($buckets[$key])) {
                $buckets[$key] = 0;
            }
            ++$buckets[$key];
        }

        $result = [];
        foreach ($buckets as $key => $count) {
            [$year, $month] = explode('-', $key);
            $result[] = [
                'year' => (int) $year,
                'month' => (int) $month,
                'count' => $count,
            ];
        }

        usort($result, static fn (array $a, array $b): int => [$a['year'], $a['month']] <=> [$b['year'], $b['month']]);

        return $result;
    }

    /**
     * Wie {@see countByMonthLast12Months()}, Rohbucketing Y-m → Geschlechtsschlüssel → Anzahl.
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndGenderLast12Months(): array
    {
        return $this->bucketQuery->bucketByMonthAndGenderLast12Months();
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndGenderLast12MonthsForHospitals(array $hospitalIds): array
    {
        return $this->bucketQuery->bucketByMonthAndGenderLast12MonthsForHospitals($hospitalIds);
    }

    /**
     * Monats × Geschlecht im Halbintervall [from, toExclusive).
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndGenderInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByMonthAndGenderInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyLast12Months(): array
    {
        return $this->bucketQuery->bucketByMonthAndUrgencyLast12Months();
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyLast12MonthsForHospitals(array $hospitalIds): array
    {
        return $this->bucketQuery->bucketByMonthAndUrgencyLast12MonthsForHospitals($hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByMonthAndUrgencyInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * Monatszähler: Allocations mit requiresCathlab true / requiresResus true (Schlüssel cathlab, resus).
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}> Keys Y-m
     */
    public function bucketAllocationsByMonthResourcesRequiredLast12Months(): array
    {
        return $this->bucketQuery->bucketByMonthResourcesRequiredLast12Months();
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByMonthResourcesRequiredLast12MonthsForHospitals(array $hospitalIds): array
    {
        return $this->bucketQuery->bucketByMonthResourcesRequiredLast12MonthsForHospitals($hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByMonthResourcesRequiredInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByMonthResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * Monatszähler je klinischem Merkmal (nur explizit true bzw. infection gesetzt).
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesLast12Months(): array
    {
        return $this->bucketQuery->bucketByMonthClinicalFeaturesLast12Months();
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesLast12MonthsForHospitals(array $hospitalIds): array
    {
        return $this->bucketQuery->bucketByMonthClinicalFeaturesLast12MonthsForHospitals($hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByMonthClinicalFeaturesInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * Zähler je Kalendermonat (1–12), alle Jahre aggregiert; Schlüssel cal-01 … cal-12.
     *
     * @return array<string, int>
     */
    public function countAllocationsByCalendarMonthOfYearInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateCountByCalendarMonthOfYear($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int>
     */
    public function countAllocationsByCalendarMonthOfYearInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateCountByCalendarMonthOfYear($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, int> Schlüssel Y-m-d
     */
    public function countAllocationsByDayInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->aggregateCountByDay($from, $toExclusive, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, int>
     */
    public function countAllocationsByDayInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->aggregateCountByDay($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByCalendarMonthOfYearAndGenderInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthAndGenderInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByCalendarMonthOfYearAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndGenderInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByDayAndGenderInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndGenderInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByDayAndGenderInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByCalendarMonthOfYearAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthAndUrgencyInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByCalendarMonthOfYearAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndUrgencyInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByDayAndUrgencyInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndUrgencyInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByDayAndUrgencyInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthResourcesRequiredInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByDayResourcesRequiredInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByDayResourcesRequiredInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByDayResourcesRequiredInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByDayResourcesRequiredInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthClinicalFeaturesInRangeAggregated(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthClinicalFeaturesInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthClinicalFeaturesInRangeAggregatedForHospitals(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByCalendarMonthClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByDayClinicalFeaturesInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketQuery->bucketByDayClinicalFeaturesInRange($from, $toExclusive);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByDayClinicalFeaturesInRangeForHospitals(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        array $hospitalIds,
    ): array {
        return $this->bucketQuery->bucketByDayClinicalFeaturesInRangeForHospitals($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, int>
     */
    private function aggregateCountByCalendarMonthOfYear(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach (range(1, 12) as $m) {
            $counts[sprintf('cal-%02d', $m)] = 0;
        }

        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $k = sprintf('cal-%02d', (int) $createdAt->format('n'));
            ++$counts[$k];
        }

        return $counts;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, int>
     */
    private function aggregateCountByDay(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $k = $createdAt->format('Y-m-d');
            if (!isset($counts[$k])) {
                $counts[$k] = 0;
            }
            ++$counts[$k];
        }

        return $counts;
    }

    /**
     * Häufigste Indikations-/Diagnose-Bezeichnungen: normalisierter Name, sonst Roh-Name.
     *
     * Aggregiert in PHP (wie {@see countAllocationsByMonthInRange()}), da Doctrine-DQL kein
     * GROUP BY mit COALESCE-Ausdrücken zuverlässig unterstützt.
     *
     * @param list<int>|null $hospitalIds null = keine Krankenhaus-Einschränkung
     *
     * @return list<array{label: string, count: int}>
     */
    public function fetchTopDiagnosisAggregates(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
        int $limit,
    ): array {
        if (null !== $hospitalIds && [] === $hospitalIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->join('a.indicationRaw', 'inRaw')
            ->leftJoin('a.indicationNormalized', 'inNorm')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $allocations */
        $allocations = $qb->getQuery()->getResult();

        $buckets = [];
        foreach ($allocations as $allocation) {
            $normalized = $allocation->getIndicationNormalized();
            $raw = $allocation->getIndicationRaw();

            $normName = $normalized?->getName();
            $label = (null !== $normName && '' !== $normName)
                ? $normName
                : ($raw?->getName() ?? '');
            if ('' === $label) {
                continue;
            }

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        arsort($buckets, \SORT_NUMERIC);

        $out = [];
        foreach ($buckets as $label => $count) {
            $out[] = ['label' => $label, 'count' => $count];
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function applyCreatedAtBefore(\Doctrine\ORM\QueryBuilder $qb, ?\DateTimeInterface $toExclusive): void
    {
        if (!$toExclusive instanceof \DateTimeInterface) {
            return;
        }

        $qb->andWhere('a.createdAt < :toExclusive')
            ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
    }
}
