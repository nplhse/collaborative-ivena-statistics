<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Export;

use App\Research\Application\Export\ExportFile;
use App\Research\Application\Export\ExportOutcome;
use App\Research\Application\Export\ExportRunnerInterface;
use App\Research\Application\Export\HospitalMinimum;
use App\Research\Application\Export\PlanInspector;
use App\Research\Domain\Entity\DataRequestQueryVersion;
use App\Research\Domain\Enum\QueryKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;

/**
 * Runs the query versions of a release on the export connection (ADR 045): all in one read-only `REPEATABLE READ`
 * transaction, so every file sees the same data, with a statement timeout and limits for rows and file size per query.
 * The reserved hospital column is checked against the minimum of hospitals per group and dropped before a row is
 * written; the IDs are not kept.
 *
 * @psalm-suppress UnusedClass Autowired through its interface.
 */
final readonly class ExportRunner implements ExportRunnerInterface
{
    public const int TIMEOUT_SECONDS = 120;
    public const int MAX_ROWS = 1_000_000;
    public const int MAX_FILE_BYTES = 200 * 1024 * 1024;
    private const int SAMPLE_ROWS = 5;

    public function __construct(
        private ExportConnection $exportConnection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function run(array $versions, string $directory): ExportOutcome
    {
        $started = $this->clock->now();
        $settings = [
            'isolation' => 'REPEATABLE READ READ ONLY',
            'statement_timeout_seconds' => self::TIMEOUT_SECONDS,
            'max_rows_per_query' => self::MAX_ROWS,
            'max_bytes_per_file' => self::MAX_FILE_BYTES,
            'minimum_hospitals' => HospitalMinimum::MIN_HOSPITALS,
        ];
        $connection = $this->exportConnection->get();
        if (!$connection instanceof Connection) {
            return new ExportOutcome([], $settings, 0, 'connection_missing');
        }

        try {
            $files = ExportConnection::readOnly($connection, self::TIMEOUT_SECONDS, function (Connection $connection) use ($versions, $directory): array {
                $files = [];
                foreach ($versions as $version) {
                    $files[] = $this->write($connection, $version, $directory);
                }

                return $files;
            });
        } catch (DbalException $exception) {
            $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();

            return new ExportOutcome([], $settings, $this->elapsed($started), trim((string) preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/', '', $message)));
        } catch (ExportLimitExceeded $exception) {
            return new ExportOutcome([], $settings, $this->elapsed($started), $exception->getMessage());
        }

        return new ExportOutcome($files, $settings, $this->elapsed($started));
    }

    /**
     * @throws DbalException
     * @throws ExportLimitExceeded
     */
    private function write(Connection $connection, DataRequestQueryVersion $version, string $directory): ExportFile
    {
        $query = $version->getQuery();
        $kind = $query->getKind();
        $reserved = $kind->reservedColumn();
        $path = $directory.'/'.$query->getName().'.csv';
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new \RuntimeException('Cannot write '.$path);
        }

        try {
            $result = $connection->executeQuery('SELECT * FROM ('.PlanInspector::normalize($version->getSql()).') AS export_query');
            $header = [];
            for ($index = 0; $index < $result->columnCount(); ++$index) {
                $name = $result->getColumnName($index);
                if ($name !== $reserved) {
                    $header[] = $name;
                }
            }

            fputcsv($handle, $header, escape: '');
            $rows = 0;
            $hospitals = [];
            $groups = [];
            $below = 0;
            $belowRows = [];
            while (false !== ($row = $result->fetchAssociative())) {
                if (++$rows > self::MAX_ROWS) {
                    throw new ExportLimitExceeded(\sprintf('The query %s returns more than %d rows.', $query->getName(), self::MAX_ROWS));
                }

                $ids = HospitalMinimum::parse($row[$reserved] ?? null);
                unset($row[$reserved]);
                foreach ($ids as $id) {
                    $hospitals[$id] = true;
                }

                if (QueryKind::Aggregated === $kind) {
                    $count = \count($ids);
                    $groups[$count] = ($groups[$count] ?? 0) + 1;
                    if ($count < HospitalMinimum::MIN_HOSPITALS) {
                        ++$below;
                        if (\count($belowRows) < self::SAMPLE_ROWS) {
                            /* @var array<string, scalar|null> $row */
                            $belowRows[] = $row;
                        }
                    }
                }

                fputcsv($handle, array_map($this->cell(...), array_values($row)), escape: '');
                if (0 === $rows % 1000 && (int) ftell($handle) > self::MAX_FILE_BYTES) {
                    throw new ExportLimitExceeded(\sprintf('The file %s.csv grows beyond %d MB.', $query->getName(), intdiv(self::MAX_FILE_BYTES, 1024 * 1024)));
                }
            }
        } finally {
            fclose($handle);
        }

        $bytes = (int) filesize($path);
        if ($bytes > self::MAX_FILE_BYTES) {
            throw new ExportLimitExceeded(\sprintf('The file %s.csv grows beyond %d MB.', $query->getName(), intdiv(self::MAX_FILE_BYTES, 1024 * 1024)));
        }

        ksort($groups);

        return new ExportFile($query->getName(), $path, $rows, $bytes, (string) hash_file('sha256', $path), \count($hospitals), $groups, $below, $belowRows);
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => (string) json_encode($value, \JSON_THROW_ON_ERROR),
        };
    }

    private function elapsed(\DateTimeImmutable $started): int
    {
        $microseconds = (int) $this->clock->now()->format('Uu') - (int) $started->format('Uu');

        return intdiv($microseconds, 1000);
    }
}
