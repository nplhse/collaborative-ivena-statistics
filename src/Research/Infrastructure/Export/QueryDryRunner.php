<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use App\Research\Application\Export\DryRunResult;
use App\Research\Application\Export\HospitalMinimum;
use App\Research\Application\Export\PlanInspector;
use App\Research\Application\Export\QueryDryRunnerInterface;
use App\Research\Domain\Enum\QueryKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;

/**
 * Runs an export query once for the administrator before release (ADR 045): read-only transaction, time limit and row
 * limit on the export connection. Reports the result columns, a few rows, the hospitals involved and the groups
 * below the minimum of contributing hospitals; the reserved hospital column never leaves this class.
 *
 * @psalm-suppress UnusedClass Autowired through its interface.
 */
final readonly class QueryDryRunner implements QueryDryRunnerInterface
{
    public const int TIMEOUT_SECONDS = 30;
    public const int MAX_ROWS = 50000;
    private const int SAMPLE_ROWS = 5;

    public function __construct(
        private ExportConnection $exportConnection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function run(string $sql, QueryKind $kind): DryRunResult
    {
        $started = $this->clock->now();
        $connection = $this->exportConnection->get();
        if (!$connection instanceof Connection) {
            return new DryRunResult($started, 0, error: 'connection_missing');
        }

        $sql = PlanInspector::normalize($sql);
        $reserved = $kind->reservedColumn();
        try {
            return ExportConnection::readOnly($connection, self::TIMEOUT_SECONDS, function (Connection $connection) use ($sql, $kind, $reserved, $started): DryRunResult {
                $result = $connection->executeQuery('SELECT * FROM ('.$sql.') AS dry_run LIMIT '.(self::MAX_ROWS + 1));
                $rows = 0;
                $columns = null;
                $sample = [];
                $hospitals = [];
                $below = 0;
                $belowRows = [];
                while (false !== ($row = $result->fetchAssociative())) {
                    if (++$rows > self::MAX_ROWS) {
                        return $this->result($started, $rows - 1, $columns ?? [], $sample, \count($hospitals), $below, $belowRows, true);
                    }

                    $ids = HospitalMinimum::parse($row[$reserved] ?? null);
                    unset($row[$reserved]);
                    /** @var array<string, scalar|null> $row */
                    $columns ??= array_keys($row);
                    foreach ($ids as $id) {
                        $hospitals[$id] = true;
                    }

                    if (\count($sample) < self::SAMPLE_ROWS) {
                        $sample[] = $row;
                    }

                    if (QueryKind::Aggregated === $kind && \count($ids) < HospitalMinimum::MIN_HOSPITALS) {
                        ++$below;
                        if (\count($belowRows) < self::SAMPLE_ROWS) {
                            $belowRows[] = $row;
                        }
                    }
                }

                return $this->result($started, $rows, $columns ?? [], $sample, \count($hospitals), $below, $belowRows, false);
            });
        } catch (DbalException $exception) {
            $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

            return new DryRunResult($started, $this->elapsed($started), error: mb_substr(trim((string) preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/', '', $message)), 0, 500));
        }
    }

    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $sample
     * @param list<array<string, scalar|null>> $belowRows
     */
    private function result(\DateTimeImmutable $started, int $rows, array $columns, array $sample, int $hospitals, int $below, array $belowRows, bool $truncated): DryRunResult
    {
        return new DryRunResult($started, $this->elapsed($started), $rows, $columns, $sample, $hospitals, $below, $belowRows, $truncated);
    }

    private function elapsed(\DateTimeImmutable $started): int
    {
        $microseconds = (int) $this->clock->now()->format('Uu') - (int) $started->format('Uu');

        return intdiv($microseconds, 1000);
    }
}
