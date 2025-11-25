<?php

namespace App\Import\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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

    public function getPaginator(ListImportQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
            ->leftJoin(
                Hospital::class,
                'h',
                Join::WITH,
                'i.hospital = h.id'
            )
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $qb->orderBy('i.'.$queryParametersDTO->sortBy, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('i.name', ':search'))
                ->orWhere($qb->expr()->like('h.name', ':search'))
                ->setParameter('search', '%'.$queryParametersDTO->search.'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    /**
     * @return array<int, array{year: int, month: int, count: int}>
     */
    public function countByMonthLast12Months(): array
    {
        $from = (new \DateTimeImmutable('first day of this month'))
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
                'count' => (int) $count,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return [$a['year'], $a['month']] <=> [$b['year'], $b['month']];
        });

        return $result;
    }
}
