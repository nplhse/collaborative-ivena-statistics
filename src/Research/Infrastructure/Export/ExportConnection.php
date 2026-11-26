<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The export connection of data extract requests (ADR 045). Without RESEARCH_DATABASE_URL there is none: query checks,
 * dry runs and exports stay disabled, and nothing falls back to the application user. In production it is the
 * hosting's read-only user; `state()` reports whether its role could write after all.
 */
final readonly class ExportConnection
{
    public const string DISABLED = 'disabled';
    public const string READ_ONLY = 'read_only';
    public const string WRITABLE = 'writable';
    public const string UNREACHABLE = 'unreachable';

    /**
     * True when the current role is a superuser, may create objects in the schema public or may change any of its
     * tables. Under SET ROLE it checks that role.
     */
    private const string WRITABLE_SQL = <<<'SQL'
        SELECT r.rolsuper
            OR has_schema_privilege(current_user, 'public', 'CREATE')
            OR EXISTS (
                SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
                  AND has_table_privilege(current_user, c.oid, 'INSERT, UPDATE, DELETE, TRUNCATE')
            )
        FROM pg_roles r WHERE r.rolname = current_user
        SQL;

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private ManagerRegistry $registry,
        #[Autowire(env: 'default::RESEARCH_DATABASE_URL')]
        private ?string $url,
        #[Autowire(env: 'default::RESEARCH_EXPECT_READ_ONLY')]
        private ?string $expectReadOnly = null,
    ) {
    }

    /**
     * Whether checks, dry runs and exports must refuse this connection because its role could change data (#1690). RESEARCH_EXPECT_READ_ONLY=0 accepts a writable connection, for the test environment, which shares the
     * application user.
     *
     * @throws DbalException
     */
    public function refusesWriting(Connection $connection): bool
    {
        if (\in_array($this->expectReadOnly, ['0', 'false'], true)) {
            return false;
        }

        return self::READ_ONLY !== self::inspect($connection);
    }

    public function isConfigured(): bool
    {
        return null !== $this->url && '' !== trim($this->url);
    }

    public function get(): ?Connection
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $connection = $this->registry->getConnection('research');

        return $connection instanceof Connection ? $connection : null;
    }

    /**
     * Whether the export connection is set up and can only read (ADR 045): `disabled` without RESEARCH_DATABASE_URL,
     * `unreachable` when it fails, `writable` when its role could change data, else `read_only`.
     */
    public function state(): string
    {
        $connection = $this->get();
        if (!$connection instanceof Connection) {
            return self::DISABLED;
        }

        try {
            return self::inspect($connection);
        } catch (DbalException) {
            return self::UNREACHABLE;
        }
    }

    /**
     * @throws DbalException
     */
    public static function inspect(Connection $connection): string
    {
        return \in_array($connection->fetchOne(self::WRITABLE_SQL), [true, 't', 1, '1'], true) ? self::WRITABLE : self::READ_ONLY;
    }

    /**
     * Runs $work in a read-only transaction with a statement timeout, REPEATABLE READ so that all queries of one export
     * see the same data, and always rolls back. Inside an outer transaction (the test database wrapper) it becomes a
     * read-only savepoint.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    public static function readOnly(Connection $connection, int $timeoutSeconds, callable $work): mixed
    {
        if (!$connection->isTransactionActive()) {
            $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        }

        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->executeStatement(\sprintf("SET LOCAL statement_timeout = '%ds'", $timeoutSeconds));

            return $work($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
