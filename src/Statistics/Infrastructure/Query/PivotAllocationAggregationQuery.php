<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\PivotColAxis;
use App\Statistics\Application\DTO\PivotRowAxis;
use App\Statistics\Infrastructure\Entity\AllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Pivot analysis only: 2D counts from allocations (not via AllocationRepository).
 *
 * @phpstan-type PivotCell array{row_key: string, col_key: string, cnt: int, row_label?: string}
 */
final readonly class PivotAllocationAggregationQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProjectionFilterApplier $filterApplier,
        private PivotValueMapper $pivotValueMapper,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds null = no hospital filter; [] = no matches
     *
     * @return list<PivotCell>
     */
    public function fetchCells(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        ?array $hospitalIds,
        PivotRowAxis $row,
        PivotColAxis $col,
    ): array {
        if (\is_array($hospitalIds) && [] === $hospitalIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->from(AllocationStatsProjection::class, 'a');
        $this->filterApplier->applyCreatedAtRange($qb, 'a.createdAt', $from, $toExclusive);
        $this->filterApplier->applyHospitalScope($qb, 'a.hospitalId', $hospitalIds);

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
        $qb->select('a.departmentId AS deptId', 'd.name AS deptName', 'a.urgencyCode AS urgency', 'COUNT(a.id) AS cnt')
            ->leftJoin(\App\Allocation\Domain\Entity\Department::class, 'd', 'WITH', 'd.id = a.departmentId')
            ->groupBy('deptId', 'deptName', 'urgency');

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $this->mapDepartmentUrgencyRows($rows);
    }

    /**
     * @return list<PivotCell>
     */
    private function executeAgeBucketByGender(QueryBuilder $qb): array
    {
        $ageExpr = trim(PivotValueMapper::AGE_BUCKET_CASE);
        // DQL: GroupByItem disallows CASE — only paths or SELECT result aliases (Parser::GroupByItem).
        $qb->select("{$ageExpr} AS rk", 'a.genderCode AS gender', 'COUNT(a.id) AS cnt')
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
        $qb->select('a.urgencyCode AS urgency', 'a.genderCode AS gender', 'COUNT(a.id) AS cnt')
            ->groupBy('urgency', 'gender');

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
            $urgency = isset($row['urgency']) ? (int) $row['urgency'] : 0;
            $colKey = (string) $urgency;
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
            $gender = isset($row['gender']) ? (int) $row['gender'] : 0;
            $colKey = $this->pivotValueMapper->genderKeyFromCode($gender);

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
            $urgency = isset($row['urgency']) ? (int) $row['urgency'] : 0;
            $gender = isset($row['gender']) ? (int) $row['gender'] : 0;
            $rk = (string) $urgency;
            $colKey = $this->pivotValueMapper->genderKeyFromCode($gender);

            $out[] = [
                'row_key' => $rk,
                'col_key' => $colKey,
                'cnt' => isset($row['cnt']) ? (int) $row['cnt'] : 0,
            ];
        }

        return $out;
    }
}
