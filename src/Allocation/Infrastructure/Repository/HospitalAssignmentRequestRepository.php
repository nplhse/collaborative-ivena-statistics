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
