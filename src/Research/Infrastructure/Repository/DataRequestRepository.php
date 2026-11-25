<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Repository;

use App\Research\Domain\Entity\DataRequest;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DataRequest>
 */
final class DataRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataRequest::class);
    }

    public function findOneByPublicId(string $publicId): ?DataRequest
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }

        return $this->findOneBy(['publicId' => Uuid::fromString($publicId)]);
    }

    /**
     * The list every participant sees (ADR 045): every request that was submitted at least once, and the viewer's
     * own drafts. Newest first; drafts by their creation time.
     *
     * @return list<DataRequest>
     */
    public function findVisibleTo(User $viewer): array
    {
        /** @var list<DataRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->addSelect('v')
            ->addSelect('COALESCE(r.submittedAt, r.createdAt) AS HIDDEN sortAt')
            ->join('r.versions', 'v')
            ->where('r.submittedAt IS NOT NULL OR r.requester = :viewer')
            ->setParameter('viewer', $viewer->getId(), Types::INTEGER)
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $requests;
    }

    /**
     * @return list<DataRequest>
     */
    public function findForRequester(User $requester): array
    {
        /** @var list<DataRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->addSelect('v')
            ->join('r.versions', 'v')
            ->where('r.requester = :requester')
            ->setParameter('requester', $requester->getId(), Types::INTEGER)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $requests;
    }
}
