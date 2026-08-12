<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Shared\Infrastructure\Pagination\Paginator;
use App\User\Application\Explore\UserQueryParameters;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ListExploreUsersQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UserQueryParameters $query): Paginator
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.isEnabled = true');

        $search = null !== $query->search ? trim($query->search) : null;
        if (null !== $search && '' !== $search) {
            $searchTerm = '%'.mb_strtolower($search).'%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.username)', ':search'),
                    'EXISTS (
                        SELECT 1 FROM '.Hospital::class.' hSearch
                        WHERE hSearch.owner = u AND LOWER(hSearch.name) LIKE :search
                    )',
                    'EXISTS (
                        SELECT 1 FROM '.HospitalAccessGrant::class.' gSearch
                        JOIN gSearch.hospital hGrantSearch
                        WHERE gSearch.user = u AND LOWER(hGrantSearch.name) LIKE :search
                    )',
                ),
            )->setParameter('search', $searchTerm);
        }

        if (null !== $query->hospitalId && $query->hospitalId > 0) {
            $qb->andWhere(
                'EXISTS (
                    SELECT 1 FROM '.Hospital::class.' hFilter
                    WHERE hFilter.owner = u AND hFilter.id = :hospitalId
                )
                OR EXISTS (
                    SELECT 1 FROM '.HospitalAccessGrant::class.' gFilter
                    WHERE gFilter.user = u AND IDENTITY(gFilter.hospital) = :hospitalId
                )',
            )->setParameter('hospitalId', $query->hospitalId);
        }

        if ($query->wantsParticipant()) {
            $participantIds = $this->findEnabledUserIdsWithAnyRole([
                UserRole::PARTICIPANT,
                UserRole::ADMIN,
            ]);
            $this->restrictToUserIds($qb, $participantIds, 'participantFilteredUserIds');
        }

        if ($query->wantsBoardMember()) {
            $boardMemberIds = $this->findEnabledUserIdsWithAnyRole([UserRole::BOARD_MEMBER]);
            $this->restrictToUserIds($qb, $boardMemberIds, 'boardMemberFilteredUserIds');
        }

        $direction = 'desc' === mb_strtolower($query->orderBy) ? 'DESC' : 'ASC';

        if ('createdAt' === $query->sortBy) {
            $qb->orderBy('u.createdAt', $direction)
                ->addOrderBy('u.id', 'ASC');
        } else {
            // Case-insensitive alphabetical default (username ASC), stable by id.
            $qb->addSelect('LOWER(u.username) AS HIDDEN usernameSort')
                ->orderBy('usernameSort', $direction)
                ->addOrderBy('u.username', $direction)
                ->addOrderBy('u.id', 'ASC');
        }

        return new Paginator($qb)->paginate($query->page, $query->limit);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<int>
     */
    private function findEnabledUserIdsWithAnyRole(array $roles): array
    {
        if ([] === $roles) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $conditions = [];
        $params = [];
        foreach ($roles as $index => $role) {
            $key = 'role'.$index;
            $conditions[] = sprintf('roles::text ILIKE :%s', $key);
            $params[$key] = '%'.$role.'%';
        }

        /** @var list<int|string> $ids */
        $ids = $connection->fetchFirstColumn(
            sprintf(
                'SELECT id FROM "user" WHERE is_enabled = true AND (%s)',
                implode(' OR ', $conditions),
            ),
            $params,
        );

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    /**
     * @param list<int> $userIds
     */
    private function restrictToUserIds(\Doctrine\ORM\QueryBuilder $qb, array $userIds, string $parameterName): void
    {
        if ([] === $userIds) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere(sprintf('u.id IN (:%s)', $parameterName))
            ->setParameter($parameterName, $userIds);
    }
}
