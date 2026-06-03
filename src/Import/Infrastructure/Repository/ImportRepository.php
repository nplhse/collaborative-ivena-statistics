<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Repository;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Import>
 */
final class ImportRepository extends ServiceEntityRepository
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Import::class);
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = new \DateTimeImmutable('first day of this month')
            ->modify('-11 months')
            ->setTime(0, 0, 0);

        $qb = $this->createQueryBuilder('i')
            ->where('i.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('i.createdAt', 'ASC');

        /** @var Import[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];

        foreach ($rows as $import) {
            $createdAt = $import->getCreatedAt();
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
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countImportsByMonthInRange(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('i.createdAt', 'ASC');
        if ($toExclusive instanceof \DateTimeInterface) {
            $qb->andWhere('i.createdAt < :toExclusive')
                ->setParameter('toExclusive', $toExclusive);
        }

        /** @var Import[] $rows */
        $rows = $qb->getQuery()->getResult();

        $buckets = [];
        foreach ($rows as $import) {
            $createdAt = $import->getCreatedAt();
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
     * @return array<int, array{year: int, count: int}>
     */
    public function countImportsByYearInRange(\DateTimeInterface $from, ?\DateTimeInterface $toExclusive): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.createdAt AS createdAt')
            ->where('i.createdAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('i.createdAt', 'ASC');

        if ($toExclusive instanceof \DateTimeInterface) {
            $qb->andWhere('i.createdAt < :toExclusive')
                ->setParameter('toExclusive', $toExclusive);
        }

        /** @var list<array{createdAt:\DateTimeInterface}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $bucketed = [];
        foreach ($rows as $row) {
            $year = (int) $row['createdAt']->format('Y');
            if (!isset($bucketed[$year])) {
                $bucketed[$year] = 0;
            }
            ++$bucketed[$year];
        }

        $result = [];
        foreach ($bucketed as $year => $count) {
            $result[] = [
                'year' => $year,
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{id: int, name: ?string, filePath: ?string}>
     */
    public function findIdsForRequeue(int $fromId = 1, ?int $onlyId = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id AS id', 'i.name AS name', 'i.filePath AS filePath')
            ->orderBy('i.id', 'ASC');

        if (null !== $onlyId) {
            $qb->andWhere('i.id = :onlyId')
                ->setParameter('onlyId', $onlyId);
        } else {
            $qb->andWhere('i.id >= :fromId')
                ->setParameter('fromId', $fromId);
        }

        if (null !== $limit && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array{id: int, name: ?string, filePath: ?string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * @return list<Import>
     */
    public function findRecentFailedImports(int $limit = 10): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<Import> $imports */
        $imports = $this->createQueryBuilder('i')
            ->addSelect('h')
            ->join('i.hospital', 'h')
            ->where('i.status = :failed')
            ->setParameter('failed', ImportStatus::FAILED)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $imports;
    }
}
