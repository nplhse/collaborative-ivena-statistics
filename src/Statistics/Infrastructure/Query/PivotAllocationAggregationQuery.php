<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\PivotColAxis;
use App\Statistics\Application\DTO\PivotRowAxis;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Pivot analysis only: 2D counts from allocations (not via AllocationRepository).
 *
 * @phpstan-type PivotCell array{row_key: string, col_key: string, cnt: int, row_label?: string}
 */
final readonly class PivotAllocationAggregationQuery
{
    private const string AGE_BUCKET_CASE = <<<'DQL'
CASE WHEN a.age IS NULL THEN 'unknown' WHEN a.age <= 18 THEN '0_18' WHEN a.age <= 29 THEN '19_29' WHEN a.age <= 39 THEN '30_39' WHEN a.age <= 49 THEN '40_49' WHEN a.age <= 59 THEN '50_59' WHEN a.age <= 69 THEN '60_69' WHEN a.age <= 79 THEN '70_79' WHEN a.age <= 89 THEN '80_89' WHEN a.age <= 99 THEN '90_99' ELSE '100p' END
DQL;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds null = no hospital filter; [] = no matches
     *
     * @return list<PivotCell>
     */
    public function fetchCells(
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        PivotRowAxis $row,
        PivotColAxis $col,
    ): array {
        if (\is_array($hospitalIds) && [] === $hospitalIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->from(Allocation::class, 'a')
            ->where('a.createdAt >= :from')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        $this->applyCreatedAtUpperBound($qb, $toExclusive);
        $this->applyHospitalFilter($qb, $hospitalIds);

        return match ([$row, $col]) {
            [PivotRowAxis::Department, PivotColAxis::Urgency] => $this->executeDepartmentByUrgency($qb),
            [PivotRowAxis::AgeGroup, PivotColAxis::Gender] => $this->executeAgeBucketByGender($qb),
            [PivotRowAxis::Urgency, PivotColAxis::Gender] => $this->executeUrgencyByGender($qb),
            default => [],
        };
    }

    /**
     * @return list<PivotCell>
     */
    private function executeDepartmentByUrgency(QueryBuilder $qb): array
    {
        $qb->select('d.id AS deptId', 'd.name AS deptName', 'a.urgency AS urgency', 'COUNT(a.id) AS cnt')
            ->join('a.department', 'd')
            ->groupBy('d.id', 'd.name', 'a.urgency');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $this->mapDepartmentUrgencyRows($rows);
    }

    /**
     * @return list<PivotCell>
     */
    private function executeAgeBucketByGender(QueryBuilder $qb): array
    {
        $ageExpr = trim(self::AGE_BUCKET_CASE);
        // DQL: GroupByItem disallows CASE — only paths or SELECT result aliases (Parser::GroupByItem).
        $qb->select("{$ageExpr} AS rk", 'a.gender AS gender', 'COUNT(a.id) AS cnt')
            ->groupBy('rk', 'gender');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $this->mapAgeGenderRows($rows);
    }

    /**
     * @return list<PivotCell>
     */
    private function executeUrgencyByGender(QueryBuilder $qb): array
    {
        $qb->select('a.urgency AS urgency', 'a.gender AS gender', 'COUNT(a.id) AS cnt')
            ->groupBy('a.urgency', 'a.gender');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $this->mapUrgencyGenderRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PivotCell>
     */
    private function mapDepartmentUrgencyRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $urgency = $row['urgency'] ?? null;
            $colKey = $urgency instanceof AllocationUrgency ? (string) $urgency->value : (string) $urgency;
            $deptId = $row['deptId'] ?? null;
            $name = isset($row['deptName']) ? (string) $row['deptName'] : '';
            $rk = null !== $deptId ? (string) $deptId : '0';
            $out[] = [
                'row_key' => $rk,
                'col_key' => $colKey,
                'cnt' => isset($row['cnt']) ? (int) $row['cnt'] : 0,
                'row_label' => '' !== $name ? $name : $rk,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PivotCell>
     */
    private function mapAgeGenderRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $gender = $row['gender'] ?? null;
            $colKey = $gender instanceof AllocationGender ? $gender->value : (string) $gender;

            $out[] = [
                'row_key' => (string) ($row['rk'] ?? ''),
                'col_key' => $colKey,
                'cnt' => isset($row['cnt']) ? (int) $row['cnt'] : 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PivotCell>
     */
    private function mapUrgencyGenderRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $urgency = $row['urgency'] ?? null;
            $gender = $row['gender'] ?? null;
            $rk = $urgency instanceof AllocationUrgency ? (string) $urgency->value : (string) $urgency;
            $colKey = $gender instanceof AllocationGender ? $gender->value : (string) $gender;

            $out[] = [
                'row_key' => $rk,
                'col_key' => $colKey,
                'cnt' => isset($row['cnt']) ? (int) $row['cnt'] : 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    private function applyHospitalFilter(QueryBuilder $qb, ?array $hospitalIds): void
    {
        if (null === $hospitalIds) {
            return;
        }

        $qb->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('hospitalIds', $hospitalIds);
    }

    private function applyCreatedAtUpperBound(QueryBuilder $qb, ?\DateTimeImmutable $toExclusive): void
    {
        if (!$toExclusive instanceof \DateTimeImmutable) {
            return;
        }

        $qb->andWhere('a.createdAt < :toExclusive')
            ->setParameter('toExclusive', $toExclusive, Types::DATETIME_IMMUTABLE);
    }
}
