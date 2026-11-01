<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Finds users whose effective roles include a role: the stored roles plus `ROLE_USER`,
 * expanded through the security role hierarchy (for example `ROLE_ADMIN` inherits
 * `ROLE_PARTICIPANT`). The enabled flag is not considered.
 */
final readonly class UserIdsWithEffectiveRoleQuery
{
    public function __construct(
        private Connection $connection,
        private RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(string $role): array
    {
        /** @var list<array{id: int|string, roles: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, roles FROM "user" ORDER BY id');

        $ids = [];
        foreach ($rows as $row) {
            if (\in_array($role, $this->effectiveRoles($row['roles']), true)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function effectiveRoles(?string $storedRoles): array
    {
        $decoded = null !== $storedRoles ? json_decode($storedRoles, true) : [];
        $roles = \is_array($decoded) ? array_values(array_filter($decoded, \is_string(...))) : [];
        $roles[] = UserRole::USER;

        return $this->roleHierarchy->getReachableRoleNames(array_values(array_unique($roles)));
    }
}
