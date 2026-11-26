<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Repository;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestRelease;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Enum\ReleaseStatus;
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

    /**
     * Requests in a vote whose open round reached its deadline (scheduler job, ADR 045).
     *
     * @return list<DataRequest>
     */
    public function findWithRoundDueBy(\DateTimeImmutable $now): array
    {
        /** @var list<DataRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->join('r.rounds', 'round')
            ->where('r.status = :status')
            ->andWhere('round.closedAt IS NULL')
            ->andWhere('round.deadline <= :now')
            ->setParameter('status', DataRequestStatus::InReview->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $requests;
    }

    /**
     * Requests whose time for a requested revision ran out (scheduler job, ADR 045).
     *
     * @return list<DataRequest>
     */
    public function findWithRevisionDueBy(\DateTimeImmutable $now): array
    {
        /** @var list<DataRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.revisionDeadline <= :now')
            ->setParameter('status', DataRequestStatus::RevisionRequested->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $requests;
    }

    /**
     * Export runs whose download period ended and that still count as available (cleanup job, ADR 045).
     *
     * @return list<DataRequestExportRun>
     */
    public function findExportRunsExpiredBy(\DateTimeImmutable $now): array
    {
        /** @var list<DataRequestExportRun> $runs */
        $runs = $this->getEntityManager()->createQueryBuilder()
            ->select('run')
            ->from(DataRequestExportRun::class, 'run')
            ->where('run.status = :status')
            ->andWhere('run.availableUntil <= :now')
            ->setParameter('status', ExportRunStatus::Available->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('run.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * File names of the archives that runs still refer to.
     *
     * @return list<string>
     */
    public function findArchiveFilesInUse(): array
    {
        /** @var list<string> $files */
        $files = $this->getEntityManager()->createQueryBuilder()
            ->select('run.archiveFile')
            ->from(DataRequestExportRun::class, 'run')
            ->where('run.archiveFile IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return $files;
    }

    /**
     * Releases whose three months passed without an export (cleanup job, ADR 045).
     *
     * @return list<DataRequestRelease>
     */
    public function findReleasesExpiredBy(\DateTimeImmutable $now): array
    {
        /** @var list<DataRequestRelease> $releases */
        $releases = $this->getEntityManager()->createQueryBuilder()
            ->select('release')
            ->from(DataRequestRelease::class, 'release')
            ->where('release.status = :status')
            ->andWhere('release.validUntil <= :now')
            ->setParameter('status', ReleaseStatus::Active->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('release.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $releases;
    }
}
