<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\User\Application\Explore\UserHospitalRelation;
use App\User\Application\Explore\UserHospitalSummary;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserHospitalRelationsQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<UserHospitalSummary>>
     */
    public function forUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ([] === $userIds) {
            return [];
        }

        $byUser = array_fill_keys($userIds, []);

        /** @var list<array{userId: int|string, publicId: mixed, name: string}> $owned */
        $owned = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(h.owner) AS userId', 'h.publicId AS publicId', 'h.name AS name')
            ->from(Hospital::class, 'h')
            ->andWhere('IDENTITY(h.owner) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        foreach ($owned as $row) {
            $publicId = $this->stringifyPublicId($row['publicId'] ?? null);
            if (null === $publicId) {
                continue;
            }

            $userId = (int) $row['userId'];
            $byUser[$userId][] = new UserHospitalSummary(
                publicId: $publicId,
                name: $row['name'],
                relation: UserHospitalRelation::OWNER,
            );
        }

        /** @var list<array{userId: int|string, publicId: mixed, name: string}> $grants */
        $grants = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(g.user) AS userId', 'h.publicId AS publicId', 'h.name AS name')
            ->from(HospitalAccessGrant::class, 'g')
            ->innerJoin('g.hospital', 'h')
            ->andWhere('IDENTITY(g.user) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        foreach ($grants as $row) {
            $publicId = $this->stringifyPublicId($row['publicId'] ?? null);
            if (null === $publicId) {
                continue;
            }

            $userId = (int) $row['userId'];
            $existing = $byUser[$userId] ?? [];
            foreach ($existing as $summary) {
                if ($summary->publicId === $publicId) {
                    continue 2;
                }
            }

            $byUser[$userId][] = new UserHospitalSummary(
                publicId: $publicId,
                name: $row['name'],
                relation: UserHospitalRelation::ACCESS,
            );
        }

        return $byUser;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function hospitalFilterChoices(): array
    {
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('h.id AS id', 'h.name AS name')
            ->from(Hospital::class, 'h')
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];
        }

        return $choices;
    }

    private function stringifyPublicId(mixed $publicId): ?string
    {
        if ($publicId instanceof \Stringable || \is_string($publicId)) {
            $value = (string) $publicId;

            return '' !== $value ? $value : null;
        }

        return null;
    }
}
