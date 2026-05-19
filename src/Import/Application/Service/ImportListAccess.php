<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\User\Domain\Entity\User;

final readonly class ImportListAccess
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private HospitalRepository $hospitalRepository,
    ) {
    }

    /**
     * @return list<int>
     */
    public function resolveAccessibleHospitalIds(User $user): array
    {
        /** @var list<int|string> $rawIds */
        $rawIds = $this->hospitalRepository
            ->getQueryBuilderForAccessibleHospitals($user)
            ->select('h.id')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $rawIds);
    }

    public function canAccessHospital(User $user, int $hospitalId): bool
    {
        return \in_array($hospitalId, $this->resolveAccessibleHospitalIds($user), true);
    }

    public function canAccessImportHospital(User $user, int $hospitalId): bool
    {
        return $this->canAccessHospital($user, $hospitalId);
    }

    public function sanitizeHospitalId(User $user, ?int $hospitalId): ?int
    {
        if (null === $hospitalId || $hospitalId <= 0) {
            return null;
        }

        return $this->canAccessHospital($user, $hospitalId) ? $hospitalId : null;
    }

    public function sanitizeOwnerId(User $user, ?int $ownerId): ?int
    {
        if (null === $ownerId || $ownerId <= 0) {
            return null;
        }

        foreach ($this->resolveOwnerChoices($user) as $owner) {
            if ($owner['id'] === $ownerId) {
                return $ownerId;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, username: string}>
     */
    public function resolveOwnerChoices(User $user): array
    {
        $qb = $this->hospitalRepository->createQueryBuilder('h')
            ->innerJoin('h.owner', 'ownerUser')
            ->select('DISTINCT ownerUser.id AS id', 'ownerUser.username AS username')
            ->orderBy('ownerUser.username', 'ASC');

        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $qb->andWhere('ownerUser = :user')
                ->setParameter('user', $user->getId());
        }

        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
            ];
        }

        return $out;
    }
}
