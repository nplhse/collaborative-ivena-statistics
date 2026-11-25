<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Application\Explore\BoardMember;
use App\User\Domain\Security\UserRole;
use Doctrine\DBAL\Connection;

/**
 * Enabled users who hold the board member role themselves (no role hierarchy: admins are not board
 * members by default), sorted by display name, or username when there is none.
 */
final readonly class BoardMembersQuery
{
    public function __construct(
        private Connection $connection,
        private UserHospitalRelationsQuery $hospitalRelationsQuery,
    ) {
    }

    /**
     * @return list<BoardMember>
     */
    public function __invoke(): array
    {
        /** @var list<array{id: int|string, username: string|null, display_name: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, username, display_name
                FROM "user"
                WHERE is_enabled = true AND CAST(roles AS jsonb) @> CAST(:role AS jsonb)
                ORDER BY LOWER(COALESCE(NULLIF(TRIM(display_name), ''), username)), id
                SQL,
            ['role' => json_encode([UserRole::BOARD_MEMBER], \JSON_THROW_ON_ERROR)],
        );

        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => null !== $row['username'] && '' !== $row['username'],
        ));
        $hospitalsByUser = $this->hospitalRelationsQuery->forUserIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
        );

        return array_map(
            static fn (array $row): BoardMember => new BoardMember(
                userId: (int) $row['id'],
                username: (string) $row['username'],
                displayName: $row['display_name'],
                hospitals: $hospitalsByUser[(int) $row['id']] ?? [],
            ),
            $rows,
        );
    }
}
