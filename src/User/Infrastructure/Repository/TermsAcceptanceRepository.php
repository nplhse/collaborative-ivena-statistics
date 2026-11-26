<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Repository;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TermsAcceptance>
 */
final class TermsAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TermsAcceptance::class);
    }

    /** The most recent acceptance of this kind, or null when the account never accepted. */
    public function latestFor(User $user, TermsAcceptanceKind $kind = TermsAcceptanceKind::Terms): ?TermsAcceptance
    {
        return $this->findOneBy(['user' => $user, 'kind' => $kind], ['acceptedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** The most recent upload authority of the account for this hospital (#1565), or null. */
    public function latestUploadAuthorityFor(User $user, Hospital $hospital): ?TermsAcceptance
    {
        return $this->findOneBy(['user' => $user, 'kind' => TermsAcceptanceKind::UploadAuthority, 'hospital' => $hospital], ['acceptedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Of these hospitals, those for which the account confirmed the upload authority (#1565): under this terms
     * version, or under any version while none is configured.
     *
     * @param list<int> $hospitalIds
     *
     * @return list<int>
     */
    public function hospitalIdsWithUploadAuthority(User $user, array $hospitalIds, ?string $termsVersion): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('a')
            ->select('DISTINCT IDENTITY(a.hospital) AS hospitalId')
            ->where('a.user = :user')
            ->andWhere('a.kind = :kind')
            ->andWhere('a.hospital IN (:hospitalIds)')
            ->setParameter('user', (int) $user->getId(), Types::INTEGER)
            ->setParameter('kind', TermsAcceptanceKind::UploadAuthority->value, Types::STRING)
            ->setParameter('hospitalIds', $hospitalIds, ArrayParameterType::INTEGER);
        if (null !== $termsVersion) {
            $queryBuilder->andWhere('a.termsVersion = :version')->setParameter('version', $termsVersion, Types::STRING);
        }

        /** @var list<array{hospitalId: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['hospitalId'], $rows);
    }

    /**
     * Hospitals for which the account confirmed the upload authority under an earlier version but not under this one
     * (#1565): renewed together with the terms at login, so nobody confirms twice.
     *
     * @return list<Hospital>
     */
    public function hospitalsWithUploadAuthorityToRenew(User $user, string $termsVersion): array
    {
        $earlier = $this->createQueryBuilder('a')
            ->select('DISTINCT IDENTITY(a.hospital)')
            ->where('a.user = :user')
            ->andWhere('a.kind = :kind');
        $current = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.hospital)')
            ->where('c.user = :user')
            ->andWhere('c.kind = :kind')
            ->andWhere('c.termsVersion = :version');

        /** @var list<Hospital> $hospitals */
        $hospitals = $this->getEntityManager()->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->where('h.id IN ('.$earlier->getDQL().')')
            ->andWhere('h.id NOT IN ('.$current->getDQL().')')
            ->orderBy('h.name', 'ASC')
            ->setParameter('user', (int) $user->getId(), Types::INTEGER)
            ->setParameter('kind', TermsAcceptanceKind::UploadAuthority->value, Types::STRING)
            ->setParameter('version', $termsVersion, Types::STRING)
            ->getQuery()
            ->getResult();

        return $hospitals;
    }

    /** Whether the account ever accepted this version. */
    public function hasAccepted(User $user, string $version, TermsAcceptanceKind $kind = TermsAcceptanceKind::Terms): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'kind' => $kind, 'termsVersion' => $version]);
    }
}
