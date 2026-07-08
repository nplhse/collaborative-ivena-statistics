<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\Shared\Infrastructure\Repository\PublicIdRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @psalm-suppress ClassMustBeFinal
 *
 * @extends ServiceEntityRepository<IndicationRaw>
 */
class IndicationRawRepository extends ServiceEntityRepository
{
    use PublicIdRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicationRaw::class);
    }

    public function getListPaginator(IndicationQueryParametersDTO $queryParametersDTO): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('(CASE WHEN i.updatedAt IS NOT NULL THEN i.updatedAt ELSE i.createdAt END) AS HIDDEN sortDate')
        ;

        if ('lastChange' === $queryParametersDTO->sortBy) {
            $qb->orderBy('sortDate', $queryParametersDTO->orderBy);
        } else {
            $sortField = match ($queryParametersDTO->sortBy) {
                'id' => 'i.id',
                'name' => 'i.name',
                'code' => 'i.code',
                default => 'i.code',
            };
            $qb->orderBy($sortField, $queryParametersDTO->orderBy);
        }

        if (null !== $queryParametersDTO->search) {
            $qb->andWhere($qb->expr()->like('LOWER(i.name)', ':search'))
                ->setParameter('search', '%'.mb_strtolower($queryParametersDTO->search).'%')
            ;
        }

        return new Paginator($qb)->paginate($queryParametersDTO->page, $queryParametersDTO->limit);
    }

    public function getReviewWorklistPaginator(IndicationRawReviewWorklistQueryDTO $query): Paginator
    {
        $qb = $this->createQueryBuilder('i');
        $this->applyReviewWorklistSegment($qb, $query->segment);
        $this->applyReviewWorklistSearch($qb, $query->search);
        $this->applyReviewWorklistSort($qb, $query);

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }

    public function findNextInWorklist(
        IndicationRawReviewWorklistQueryDTO $query,
        ?int $afterId = null,
    ): ?IndicationRaw {
        $qb = $this->createQueryBuilder('i')
            ->select('i');
        $this->applyReviewWorklistSegment($qb, $query->segment);
        $this->applyReviewWorklistSearch($qb, $query->search);

        $qb->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if (null !== $afterId) {
            $current = $this->find($afterId);
            if ($current instanceof IndicationRaw) {
                $createdAt = $current->getCreatedAt();
                if ($createdAt instanceof \DateTimeImmutable) {
                    $qb->andWhere('(i.createdAt > :cursorCreatedAt) OR (i.createdAt = :cursorCreatedAt AND i.id > :cursorId)')
                        ->setParameter('cursorCreatedAt', $createdAt, Types::DATETIME_IMMUTABLE)
                        ->setParameter('cursorId', $afterId);
                }
            }
        }

        $qb->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function applyReviewWorklistSegment(\Doctrine\ORM\QueryBuilder $qb, IndicationRawReviewWorklistSegment $segment): void
    {
        match ($segment) {
            IndicationRawReviewWorklistSegment::Open,
            IndicationRawReviewWorklistSegment::TopOpen => $qb->andWhere('i.reviewStatus IN (:openStatuses)')
                ->setParameter('openStatuses', [IndicationRawReviewStatus::Unreviewed, IndicationRawReviewStatus::NeedsReview]),
            IndicationRawReviewWorklistSegment::New => $qb->andWhere('i.reviewStatus IN (:openStatuses)')
                ->andWhere('i.createdAt >= :newSince')
                ->setParameter('openStatuses', [IndicationRawReviewStatus::Unreviewed, IndicationRawReviewStatus::NeedsReview])
                ->setParameter('newSince', new \DateTimeImmutable('-30 days'), Types::DATETIME_IMMUTABLE),
            IndicationRawReviewWorklistSegment::Unreviewed => $qb->andWhere('i.reviewStatus = :unreviewed')
                ->setParameter('unreviewed', IndicationRawReviewStatus::Unreviewed),
            IndicationRawReviewWorklistSegment::NeedsReview => $qb->andWhere('i.reviewStatus = :needsReview')
                ->setParameter('needsReview', IndicationRawReviewStatus::NeedsReview),
            IndicationRawReviewWorklistSegment::Matched => $qb->andWhere('i.reviewStatus = :matched')
                ->setParameter('matched', IndicationRawReviewStatus::Matched),
            IndicationRawReviewWorklistSegment::NotMatchable => $qb->andWhere('i.reviewStatus = :notMatchable')
                ->setParameter('notMatchable', IndicationRawReviewStatus::NotMatchable),
            IndicationRawReviewWorklistSegment::Ignored => $qb->andWhere('i.reviewStatus = :ignored')
                ->setParameter('ignored', IndicationRawReviewStatus::Ignored),
        };
    }

    private function applyReviewWorklistSearch(\Doctrine\ORM\QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === trim($search)) {
            return;
        }

        $qb->andWhere($qb->expr()->like('LOWER(i.name)', ':search'))
            ->setParameter('search', '%'.mb_strtolower($search).'%');
    }

    private function applyReviewWorklistSort(\Doctrine\ORM\QueryBuilder $qb, IndicationRawReviewWorklistQueryDTO $query): void
    {
        $direction = 'desc' === $query->orderBy ? 'DESC' : 'ASC';

        if ('occurrence' === $query->sortBy) {
            $qb->addSelect(
                '(SELECT COUNT(a.id) FROM '.Allocation::class.' a WHERE a.indicationRaw = i OR a.secondaryIndicationRaw = i) AS HIDDEN occurrenceCount'
            )
                ->orderBy('occurrenceCount', $direction)
                ->addOrderBy('i.createdAt', 'ASC')
                ->addOrderBy('i.id', 'ASC');

            return;
        }

        match ($query->segment) {
            IndicationRawReviewWorklistSegment::NeedsReview => $qb->orderBy('i.firstMatchedAt', $direction)
                ->addOrderBy('i.id', $direction),
            IndicationRawReviewWorklistSegment::New => $qb->orderBy('i.createdAt', 'DESC' === $direction ? 'DESC' : 'ASC')
                ->addOrderBy('i.id', 'DESC'),
            IndicationRawReviewWorklistSegment::Matched,
            IndicationRawReviewWorklistSegment::NotMatchable,
            IndicationRawReviewWorklistSegment::Ignored => $qb->orderBy('i.reviewedAt', 'DESC' === $direction ? 'DESC' : 'ASC')
                ->addOrderBy('i.id', 'DESC'),
            default => $qb->orderBy('i.createdAt', 'DESC' === $direction ? 'DESC' : 'ASC')
                ->addOrderBy('i.id', 'ASC'),
        };

        if ('firstMatchedAt' === $query->sortBy) {
            $qb->orderBy('i.firstMatchedAt', $direction)
                ->addOrderBy('i.id', $direction);
        } elseif ('reviewedAt' === $query->sortBy) {
            $qb->orderBy('i.reviewedAt', $direction)
                ->addOrderBy('i.id', $direction);
        } elseif ('createdAt' === $query->sortBy) {
            $qb->orderBy('i.createdAt', $direction)
                ->addOrderBy('i.id', $direction);
        } elseif ('name' === $query->sortBy) {
            $qb->orderBy('i.name', $direction)
                ->addOrderBy('i.id', $direction);
        } elseif ('code' === $query->sortBy) {
            $qb->orderBy('i.code', $direction)
                ->addOrderBy('i.id', $direction);
        }
    }

    /**
     * @return array<int,array{hash:string,id:int,normalized_id:int}>
     */
    public function preloadAllLight(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.id as id, r.hash AS hash, IDENTITY(r.normalized) AS normalized_id')
            ->getQuery()
            ->getArrayResult();
    }
}
