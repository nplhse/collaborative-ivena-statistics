<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<HospitalAssignmentRequest>
 */
final class HospitalAssignmentRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HospitalAssignmentRequest::class);
    }

    public function findOneByPublicId(string $publicId): ?HospitalAssignmentRequest
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }

        return $this->findOneBy(['publicId' => Uuid::fromString($publicId)]);
    }

    /**
     * Requests of one participant, newest first.
     *
     * @return list<HospitalAssignmentRequest>
     */
    public function findForRequester(User $requester): array
    {
        return $this->findBy(['requester' => $requester], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The request that describes where a participant stands (#1483): the newest pending one, otherwise the newest
     * request of any status.
     */
    public function findCurrentForRequester(User $requester): ?HospitalAssignmentRequest
    {
        /** @var HospitalAssignmentRequest|null $request */
        $request = $this->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.status = :pending THEN 0 ELSE 1 END AS HIDDEN pendingFirst')
            ->where('IDENTITY(r.requester) = :requesterId')
            ->setParameter('requesterId', $requester->getId(), Types::INTEGER)
            ->setParameter('pending', HospitalAssignmentRequestStatus::Pending->value, Types::STRING)
            ->orderBy('pendingFirst', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $request;
    }

    public function hasPendingForRequesterAndHospital(User $requester, Hospital $hospital): bool
    {
        return null !== $this->findOneBy([
            'requester' => $requester,
            'hospital' => $hospital,
            'status' => HospitalAssignmentRequestStatus::Pending,
        ]);
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->setParameter('status', HospitalAssignmentRequestStatus::Pending->value, Types::STRING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
