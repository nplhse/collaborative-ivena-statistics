<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Query;

use App\Shared\Infrastructure\Pagination\ResultCount;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Counts the rows of the filtered Explore allocation list (#1432).
 *
 * The count runs through Doctrine, so every parameter is bound like in the list query itself. It is exact
 * unless the database cannot finish it within the time limit; then it counts up to EXACT_LIMIT rows and
 * reports a lower bound above that. Results are cached per query and data version, so paging through the
 * same filter does not count again.
 */
final readonly class AllocationListResultCountQuery
{
    public const int EXACT_LIMIT = 10_000;

    private const int CACHE_TTL_SECONDS = 86_400;

    private const int FALLBACK_CACHE_TTL_SECONDS = 60;

    /**
     * Tables whose writes can change the count of a list filter: the rows themselves, hospital master data
     * (tier, size, location filters) and closures with their imports (closure-at-assignment filter).
     */
    private const array DATA_TABLES = ['allocation', 'hospital', 'import', 'closure_interval'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.allocation.list_result_count')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private int $timeoutMilliseconds = 500,
    ) {
    }

    /**
     * @param QueryBuilder $listQuery the filtered list query; its root alias must be `a`
     */
    public function count(QueryBuilder $listQuery): ?ResultCount
    {
        $countQuery = (clone $listQuery)
            ->resetDQLPart('orderBy')
            ->select('COUNT(a.id)')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getQuery();

        $sql = $countQuery->getSQL();
        $key = 'allocation_list_count.'.hash('xxh128', implode('|', [
            \is_array($sql) ? implode(' ', $sql) : $sql,
            serialize(array_map($this->normalizeParameter(...), $countQuery->getParameters()->toArray())),
            $this->dataVersion(),
        ]));

        return $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($countQuery, $listQuery): ?ResultCount {
            $exact = $this->withTimeout(static fn (): int => (int) $countQuery->getSingleScalarResult());
            if (null !== $exact) {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return ResultCount::exact($exact);
            }

            $bounded = $this->countUpToLimit($listQuery);
            $save = $bounded instanceof ResultCount;
            $item->expiresAfter(self::FALLBACK_CACHE_TTL_SECONDS);

            return $bounded;
        });
    }

    /**
     * Reads at most EXACT_LIMIT + 1 ids: cheap even when the full count is not.
     */
    private function countUpToLimit(QueryBuilder $listQuery): ?ResultCount
    {
        $query = (clone $listQuery)
            ->resetDQLPart('orderBy')
            ->select('a.id')
            ->setFirstResult(0)
            ->setMaxResults(self::EXACT_LIMIT + 1)
            ->getQuery();

        $rows = $this->withTimeout(static fn (): int => \count($query->getSingleColumnResult()));
        if (null === $rows) {
            return null;
        }

        return $rows > self::EXACT_LIMIT ? ResultCount::moreThan(self::EXACT_LIMIT) : ResultCount::exact($rows);
    }

    /**
     * Runs the count with a statement timeout that applies only to this count, also inside an outer
     * transaction: the timeout is set in a nested transaction (savepoint) and reset before it is released.
     *
     * @param \Closure(): int $count
     */
    private function withTimeout(\Closure $count): ?int
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $previous = (string) $connection->fetchOne("SELECT current_setting('statement_timeout')");
            $this->setStatementTimeout($connection, $this->timeoutMilliseconds.'ms');
            $result = $count();
            $this->setStatementTimeout($connection, $previous);
            $connection->commit();

            return $result;
        } catch (DBALException $exception) {
            $connection->rollBack();
            $this->logger->warning('Explore allocation list count failed or timed out after {timeout} ms.', [
                'timeout' => $this->timeoutMilliseconds,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function setStatementTimeout(Connection $connection, string $value): void
    {
        $connection->executeStatement("SELECT set_config('statement_timeout', ?, true)", [$value]);
    }

    /**
     * Changes whenever one of the data tables is written, including uncommitted writes of the current
     * transaction, so a cached count never outlives the rows it counted.
     */
    private function dataVersion(): string
    {
        $tables = "'".implode("', '", self::DATA_TABLES)."'";
        $writes = static fn (string $view): string => \sprintf(
            '(SELECT COALESCE(SUM(n_tup_ins + n_tup_upd + n_tup_del), 0) FROM %s WHERE relname IN (%s))',
            $view,
            $tables,
        );

        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT '.$writes('pg_stat_user_tables')." || ':' || ".$writes('pg_stat_xact_user_tables'),
        );
    }

    /**
     * @return array{string, mixed}
     */
    private function normalizeParameter(Parameter $parameter): array
    {
        return [$parameter->getName(), $this->normalizeValue($parameter->getValue())];
    }

    private function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            \is_array($value) => array_map($this->normalizeValue(...), $value),
            \is_object($value) && method_exists($value, 'getId') => $value::class.'#'.(string) $value->getId(),
            default => $value,
        };
    }
}
