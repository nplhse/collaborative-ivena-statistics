<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Allocation>
 */
final class AllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Allocation::class);
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');

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
     * Wie {@see countByMonthLast12Months()}, eingeschränkt auf die angegebenen Krankenhäuser.
     *
     * @param list<int> $hospitalIds
     *
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('from', $from)
            ->setParameter('hospitalIds', $hospitalIds)
            ->orderBy('a.createdAt', 'ASC');

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
            ->setParameter('before', $before)
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

        if ($result instanceof \DateTimeImmutable) {
            return $result;
        }

        if ($result instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($result);
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
            ->setParameter('from', $from);
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthAndGender($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndGenderLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthAndGender($from, null, $hospitalIds);
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
        return $this->bucketAllocationsByMonthAndGender($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketAllocationsByMonthAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthAndUrgency($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthAndUrgency($from, null, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByMonthAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketAllocationsByMonthAndUrgency($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketAllocationsByMonthAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds null = keine Krankenhaus-Einschränkung
     *
     * @return array<string, array<string, int>>
     */
    private function bucketAllocationsByMonthAndGender(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            $gender = $allocation->getGender() ?? AllocationGender::OTHER;
            $g = $gender->value;

            if (!isset($buckets[$ym][$g])) {
                $buckets[$ym][$g] = 0;
            }
            ++$buckets[$ym][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds null = keine Krankenhaus-Einschränkung
     *
     * @return array<string, array<string, int>>
     */
    private function bucketAllocationsByMonthAndUrgency(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            $urgency = $allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT;
            $u = sprintf('%d', $urgency->value);

            if (!isset($buckets[$ym][$u])) {
                $buckets[$ym][$u] = 0;
            }
            ++$buckets[$ym][$u];
        }

        return $buckets;
    }

    /**
     * Monatszähler: Allocations mit requiresCathlab true / requiresResus true (Schlüssel cathlab, resus).
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}> Keys Y-m
     */
    public function bucketAllocationsByMonthResourcesRequiredLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthResourcesRequired($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByMonthResourcesRequiredLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthResourcesRequired($from, null, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketAllocationsByMonthResourcesRequired($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketAllocationsByMonthResourcesRequired($from, $toExclusive, $hospitalIds);
    }

    /**
     * Monatszähler je klinischem Merkmal (nur explizit true bzw. infection gesetzt).
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthClinicalFeatures($from, null, null);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesLast12MonthsForHospitals(array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        return $this->bucketAllocationsByMonthClinicalFeatures($from, null, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByMonthClinicalFeaturesInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketAllocationsByMonthClinicalFeatures($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketAllocationsByMonthClinicalFeatures($from, $toExclusive, $hospitalIds);
    }

    /**
     * @param list<int>|null $hospitalIds null = keine Krankenhaus-Einschränkung
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function bucketAllocationsByMonthResourcesRequired(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            if (!isset($buckets[$ym])) {
                $buckets[$ym] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $requiresAny = false;
            if (true === $allocation->isRequiresCathlab()) {
                ++$buckets[$ym]['cathlab'];
                $requiresAny = true;
            }
            if (true === $allocation->isRequiresResus()) {
                ++$buckets[$ym]['resus'];
                $requiresAny = true;
            }
            if ($requiresAny) {
                ++$buckets[$ym]['with_any'];
            }
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds null = keine Krankenhaus-Einschränkung
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function bucketAllocationsByMonthClinicalFeatures(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $ym = $createdAt->format('Y-m');
            if (!isset($buckets[$ym])) {
                $buckets[$ym] = [
                    'with_physician' => 0,
                    'cpr' => 0,
                    'ventilated' => 0,
                    'shock' => 0,
                    'pregnant' => 0,
                    'infectious' => 0,
                    'with_any' => 0,
                ];
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$ym]);
        }

        return $buckets;
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
        return $this->bucketByCalendarMonthOfYearAndGender($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketByCalendarMonthOfYearAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndGenderInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketByDayAndGender($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketByDayAndGender($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByCalendarMonthOfYearAndUrgencyInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketByCalendarMonthOfYearAndUrgency($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketByCalendarMonthOfYearAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function bucketAllocationsByDayAndUrgencyInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketByDayAndUrgency($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketByDayAndUrgency($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthResourcesRequiredInRange(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketResourcesRequiredByCalendarMonthOfYear($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketResourcesRequiredByCalendarMonthOfYear($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    public function bucketAllocationsByDayResourcesRequiredInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketResourcesRequiredByDay($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketResourcesRequiredByDay($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByCalendarMonthClinicalFeaturesInRangeAggregated(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketClinicalFeaturesByCalendarMonthOfYear($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketClinicalFeaturesByCalendarMonthOfYear($from, $toExclusive, $hospitalIds);
    }

    /**
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    public function bucketAllocationsByDayClinicalFeaturesInRange(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
    ): array {
        return $this->bucketClinicalFeaturesByDay($from, $toExclusive, null);
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
        if ([] === $hospitalIds) {
            return [];
        }

        return $this->bucketClinicalFeaturesByDay($from, $toExclusive, $hospitalIds);
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
            ->setParameter('from', $from)
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
            ->setParameter('from', $from)
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
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function bucketByCalendarMonthOfYearAndGender(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            $gender = $allocation->getGender() ?? AllocationGender::OTHER;
            $g = $gender->value;

            if (!isset($buckets[$calKey][$g])) {
                $buckets[$calKey][$g] = 0;
            }
            ++$buckets[$calKey][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function bucketByDayAndGender(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            $gender = $allocation->getGender() ?? AllocationGender::OTHER;
            $g = $gender->value;

            if (!isset($buckets[$dayKey][$g])) {
                $buckets[$dayKey][$g] = 0;
            }
            ++$buckets[$dayKey][$g];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function bucketByCalendarMonthOfYearAndUrgency(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            $urgency = $allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT;
            $u = sprintf('%d', $urgency->value);

            if (!isset($buckets[$calKey][$u])) {
                $buckets[$calKey][$u] = 0;
            }
            ++$buckets[$calKey][$u];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array<string, int>>
     */
    private function bucketByDayAndUrgency(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array<string, int>> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            $urgency = $allocation->getUrgency() ?? AllocationUrgency::OUTPATIENT;
            $u = sprintf('%d', $urgency->value);

            if (!isset($buckets[$dayKey][$u])) {
                $buckets[$dayKey][$u] = 0;
            }
            ++$buckets[$dayKey][$u];
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function bucketResourcesRequiredByCalendarMonthOfYear(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            if (!isset($buckets[$calKey])) {
                $buckets[$calKey] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $requiresAny = false;
            if (true === $allocation->isRequiresCathlab()) {
                ++$buckets[$calKey]['cathlab'];
                $requiresAny = true;
            }
            if (true === $allocation->isRequiresResus()) {
                ++$buckets[$calKey]['resus'];
                $requiresAny = true;
            }
            if ($requiresAny) {
                ++$buckets[$calKey]['with_any'];
            }
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{cathlab: int, resus: int, with_any: int}>
     */
    private function bucketResourcesRequiredByDay(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        /** @var array<string, array{cathlab: int, resus: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            if (!isset($buckets[$dayKey])) {
                $buckets[$dayKey] = ['cathlab' => 0, 'resus' => 0, 'with_any' => 0];
            }

            $requiresAny = false;
            if (true === $allocation->isRequiresCathlab()) {
                ++$buckets[$dayKey]['cathlab'];
                $requiresAny = true;
            }
            if (true === $allocation->isRequiresResus()) {
                ++$buckets[$dayKey]['resus'];
                $requiresAny = true;
            }
            if ($requiresAny) {
                ++$buckets[$dayKey]['with_any'];
            }
        }

        return $buckets;
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function bucketClinicalFeaturesByCalendarMonthOfYear(
        \DateTimeInterface $from,
        ?\DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->accumulateClinicalBucketsForCalendarMonthKeys($rows);
    }

    /**
     * @param list<int>|null $hospitalIds
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function bucketClinicalFeaturesByDay(
        \DateTimeInterface $from,
        \DateTimeInterface $toExclusive,
        ?array $hospitalIds,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('a.createdAt', 'ASC');
        $this->applyCreatedAtBefore($qb, $toExclusive);

        if (null !== $hospitalIds) {
            $qb->andWhere('a.hospital IN (:hospitalIds)')
                ->setParameter('hospitalIds', $hospitalIds);
        }

        /** @var Allocation[] $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->accumulateClinicalBucketsForDayKeys($rows);
    }

    /**
     * @param iterable<int, Allocation> $rows
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function accumulateClinicalBucketsForCalendarMonthKeys(iterable $rows): array
    {
        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $calKey = sprintf('cal-%02d', (int) $createdAt->format('n'));
            if (!isset($buckets[$calKey])) {
                $buckets[$calKey] = [
                    'with_physician' => 0,
                    'cpr' => 0,
                    'ventilated' => 0,
                    'shock' => 0,
                    'pregnant' => 0,
                    'infectious' => 0,
                    'with_any' => 0,
                ];
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$calKey]);
        }

        return $buckets;
    }

    /**
     * @param iterable<int, Allocation> $rows
     *
     * @return array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}>
     */
    private function accumulateClinicalBucketsForDayKeys(iterable $rows): array
    {
        /** @var array<string, array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int}> $buckets */
        $buckets = [];
        foreach ($rows as $allocation) {
            $createdAt = $allocation->getCreatedAt();
            if (!$createdAt) {
                continue;
            }

            $dayKey = $createdAt->format('Y-m-d');
            if (!isset($buckets[$dayKey])) {
                $buckets[$dayKey] = [
                    'with_physician' => 0,
                    'cpr' => 0,
                    'ventilated' => 0,
                    'shock' => 0,
                    'pregnant' => 0,
                    'infectious' => 0,
                    'with_any' => 0,
                ];
            }

            $this->incrementClinicalFeatureCells($allocation, $buckets[$dayKey]);
        }

        return $buckets;
    }

    /**
     * @param array{with_physician: int, cpr: int, ventilated: int, shock: int, pregnant: int, infectious: int, with_any: int} $cell
     */
    private function incrementClinicalFeatureCells(Allocation $allocation, array &$cell): void
    {
        $hasAny = false;
        if (true === $allocation->isWithPhysician()) {
            ++$cell['with_physician'];
            $hasAny = true;
        }
        if (true === $allocation->isCPR()) {
            ++$cell['cpr'];
            $hasAny = true;
        }
        if (true === $allocation->isVentilated()) {
            ++$cell['ventilated'];
            $hasAny = true;
        }
        if (true === $allocation->isShock()) {
            ++$cell['shock'];
            $hasAny = true;
        }
        if (true === $allocation->isPregnant()) {
            ++$cell['pregnant'];
            $hasAny = true;
        }
        if ($allocation->getInfection() instanceof \App\Allocation\Domain\Entity\Infection) {
            ++$cell['infectious'];
            $hasAny = true;
        }
        if ($hasAny) {
            ++$cell['with_any'];
        }
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
            ->setParameter('from', $from);
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
                : (string) ($raw?->getName() ?? '');
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
            ->setParameter('toExclusive', $toExclusive);
    }
}
