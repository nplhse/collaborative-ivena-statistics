<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Domain\Entity\LoginFailureDaily;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads for the admin view of failed sign-ins (#1487): recent daily rows and the accounts locked right now.
 */
final readonly class LoginFailureOverviewQuery
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /** @return list<LoginFailureDaily> newest first */
    public function dailySince(\DateTimeImmutable $from): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(LoginFailureDaily::class, 'd')
            ->where('d.date >= :from')
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('d.date', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter((array) $rows, static fn (mixed $row): bool => $row instanceof LoginFailureDaily));
    }

    /** @return list<array{id: int, username: string, lockedUntil: \DateTimeImmutable}> soonest end first */
    public function lockedAccounts(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, username, login_locked_until FROM "user" WHERE login_locked_until > :now ORDER BY login_locked_until, id',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'lockedUntil' => new \DateTimeImmutable((string) $row['login_locked_until']),
        ], $rows);
    }
}
