<?php

namespace App\Repository;

use App\Entity\Allocation;
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
        $from = (new \DateTimeImmutable('first day of this month'))
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
                'count' => (int) $count,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return [$a['year'], $a['month']] <=> [$b['year'], $b['month']];
        });

        return $result;
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
}
