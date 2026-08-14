<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\User\Application\Explore\UserHospitalRelation;
use App\User\Application\Explore\UserHospitalSummary;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

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

        /** @var list<array<string, mixed>> $owned */
        $owned = $this->withHospitalLookups(
            $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(h.owner) AS userId', 'h.publicId AS publicId', 'h.name AS name')
                ->from(Hospital::class, 'h')
                ->andWhere('IDENTITY(h.owner) IN (:userIds)')
                ->setParameter('userIds', $userIds)
                ->orderBy('h.name', 'ASC')
        )
            ->getQuery()
            ->getArrayResult();

        foreach ($owned as $row) {
            $summary = $this->summaryFromRow($row, UserHospitalRelation::OWNER);
            if (!$summary instanceof UserHospitalSummary) {
                continue;
            }

            $byUser[(int) $row['userId']][] = $summary;
        }

        /** @var list<array<string, mixed>> $grants */
        $grants = $this->withHospitalLookups(
            $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(g.user) AS userId', 'h.publicId AS publicId', 'h.name AS name')
                ->from(HospitalAccessGrant::class, 'g')
                ->innerJoin('g.hospital', 'h')
                ->andWhere('IDENTITY(g.user) IN (:userIds)')
                ->setParameter('userIds', $userIds)
                ->orderBy('h.name', 'ASC')
        )
            ->getQuery()
            ->getArrayResult();

        foreach ($grants as $row) {
            $summary = $this->summaryFromRow($row, UserHospitalRelation::ACCESS);
            if (!$summary instanceof UserHospitalSummary) {
                continue;
            }

            $userId = (int) $row['userId'];
            foreach ($byUser[$userId] ?? [] as $existing) {
                if ($existing->publicId === $summary->publicId) {
                    continue 2;
                }
            }

            $byUser[$userId][] = $summary;
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

    private function withHospitalLookups(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect(
                'da.name AS dispatchAreaName',
                's.name AS stateName',
                'h.location AS location',
                'h.tier AS tier',
                'h.size AS size',
                'h.beds AS beds',
            )
            ->leftJoin('h.dispatchArea', 'da')
            ->leftJoin('h.state', 's');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summaryFromRow(array $row, string $relation): ?UserHospitalSummary
    {
        $publicId = $this->stringifyPublicId($row['publicId'] ?? null);
        $name = $this->nullableString($row['name'] ?? null);
        if (null === $publicId || null === $name) {
            return null;
        }

        $beds = $row['beds'] ?? null;

        return new UserHospitalSummary(
            publicId: $publicId,
            name: $name,
            relation: $relation,
            dispatchAreaName: $this->nullableString($row['dispatchAreaName'] ?? null),
            stateName: $this->nullableString($row['stateName'] ?? null),
            location: $this->backedEnumValue($row['location'] ?? null),
            tier: $this->backedEnumValue($row['tier'] ?? null),
            size: $this->backedEnumValue($row['size'] ?? null),
            beds: \is_numeric($beds) ? (int) $beds : null,
        );
    }

    private function stringifyPublicId(mixed $publicId): ?string
    {
        if ($publicId instanceof \Stringable || \is_string($publicId)) {
            $value = (string) $publicId;

            return '' !== $value ? $value : null;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function backedEnumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $this->nullableString($value);
    }
}
