<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use App\Research\Application\Export\PlanInspector;
use App\Research\Application\Export\QueryCheckerInterface;
use App\Research\Application\Export\QueryViolation;
use App\Research\Domain\Enum\QueryKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Checks an export query before it can be released (ADR 045): the text first, then EXPLAIN (VERBOSE, FORMAT JSON) and
 * the result column names on the export connection, in a read-only transaction. The query itself is not run.
 *
 * @psalm-suppress UnusedClass Autowired through its interface.
 */
final readonly class QueryGuard implements QueryCheckerInterface
{
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private ExportConnection $exportConnection,
    ) {
    }

    /**
     * @return list<QueryViolation> empty when the query passes
     */
    #[\Override]
    public function check(string $sql, QueryKind $kind): array
    {
        $violations = PlanInspector::inspectText($sql);
        if ([] !== $violations) {
            return $violations;
        }

        $connection = $this->exportConnection->get();
        if (!$connection instanceof Connection) {
            return [new QueryViolation(QueryViolation::CONNECTION_MISSING)];
        }

        $sql = PlanInspector::normalize($sql);
        try {
            if ($this->exportConnection->refusesWriting($connection)) {
                return [new QueryViolation(QueryViolation::CONNECTION_WRITABLE)];
            }

            return ExportConnection::readOnly($connection, self::TIMEOUT_SECONDS, static function (Connection $connection) use ($sql, $kind): array {
                $explained = $connection->fetchOne('EXPLAIN (VERBOSE, FORMAT JSON) '.$sql);
                $decoded = json_decode(\is_string($explained) ? $explained : 'null', true);
                $plan = \is_array($decoded) && \is_array($decoded[0]['Plan'] ?? null) ? $decoded[0]['Plan'] : [];

                $result = $connection->executeQuery('SELECT * FROM ('.$sql.') AS checked_query LIMIT 0');
                $columns = [];
                for ($index = 0; $index < $result->columnCount(); ++$index) {
                    $columns[] = $result->getColumnName($index);
                }

                return PlanInspector::inspectPlan($plan, $columns, $kind);
            });
        } catch (DbalException $exception) {
            return [new QueryViolation(QueryViolation::SQL_ERROR, ['message' => $this->message($exception)])];
        }
    }

    /** The database's own message without the driver prefix, short enough to show. */
    private function message(\Throwable $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

        return mb_substr(trim((string) preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/', '', $message)), 0, 500);
    }
}
