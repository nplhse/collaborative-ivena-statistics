<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Query;

use App\Import\Domain\Enum\ImportStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class KpiDayAggregationQuery
{
    private const string TIMEZONE = 'Europe/Berlin';

    /** @var list<string> */
    private const array FINAL_STATUSES = [
        ImportStatus::COMPLETED->value,
        ImportStatus::PARTIAL->value,
        ImportStatus::FAILED->value,
        ImportStatus::CANCELLED->value,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{
     *     hospitalId: int,
     *     importsCount: int,
     *     successfulImportsCount: int,
     *     failedImportsCount: int,
     *     recordsTotal: int,
     *     recordsRejected: int,
     * }>
     */
    public function fetchPerHospital(\DateTimeImmutable $day): array
    {
        [$from, $to] = $this->dayBounds($day);

        $sql = <<<'SQL'
            SELECT
                i.hospital_id AS hospital_id,
                COUNT(*)::int AS imports_count,
                SUM(CASE WHEN i.status IN (:completed, :partial) THEN 1 ELSE 0 END)::int AS successful_imports_count,
                SUM(CASE WHEN i.status = :failed THEN 1 ELSE 0 END)::int AS failed_imports_count,
                COALESCE(SUM(i.row_count), 0)::int AS records_total,
                COALESCE(SUM(i.rows_rejected), 0)::int AS records_rejected
            FROM import i
            WHERE i.created_at >= :from
              AND i.created_at < :to
              AND i.status IN (:final_statuses)
            GROUP BY i.hospital_id
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'completed' => ImportStatus::COMPLETED->value,
            'partial' => ImportStatus::PARTIAL->value,
            'failed' => ImportStatus::FAILED->value,
            'final_statuses' => self::FINAL_STATUSES,
        ], [
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
            'completed' => ParameterType::STRING,
            'partial' => ParameterType::STRING,
            'failed' => ParameterType::STRING,
            'final_statuses' => ArrayParameterType::STRING,
        ]);

        $result = [];
        foreach ($rows as $row) {
            $recordsTotal = $this->toInt($row['records_total'] ?? 0);
            $result[] = [
                'hospitalId' => $this->toInt($row['hospital_id'] ?? 0),
                'importsCount' => $this->toInt($row['imports_count'] ?? 0),
                'successfulImportsCount' => $this->toInt($row['successful_imports_count'] ?? 0),
                'failedImportsCount' => $this->toInt($row['failed_imports_count'] ?? 0),
                'recordsTotal' => $recordsTotal,
                'recordsRejected' => $this->toInt($row['records_rejected'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *     importsCount: int,
     *     successfulImportsCount: int,
     *     failedImportsCount: int,
     *     recordsTotal: int,
     *     recordsRejected: int,
     * }|null
     */
    public function fetchGlobal(\DateTimeImmutable $day): ?array
    {
        [$from, $to] = $this->dayBounds($day);

        $sql = <<<'SQL'
            SELECT
                COUNT(*)::int AS imports_count,
                SUM(CASE WHEN i.status IN (:completed, :partial) THEN 1 ELSE 0 END)::int AS successful_imports_count,
                SUM(CASE WHEN i.status = :failed THEN 1 ELSE 0 END)::int AS failed_imports_count,
                COALESCE(SUM(i.row_count), 0)::int AS records_total,
                COALESCE(SUM(i.rows_rejected), 0)::int AS records_rejected
            FROM import i
            WHERE i.created_at >= :from
              AND i.created_at < :to
              AND i.status IN (:final_statuses)
            SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'completed' => ImportStatus::COMPLETED->value,
            'partial' => ImportStatus::PARTIAL->value,
            'failed' => ImportStatus::FAILED->value,
            'final_statuses' => self::FINAL_STATUSES,
        ], [
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
            'completed' => ParameterType::STRING,
            'partial' => ParameterType::STRING,
            'failed' => ParameterType::STRING,
            'final_statuses' => ArrayParameterType::STRING,
        ]);

        if (false === $row || 0 === $this->toInt($row['imports_count'] ?? 0)) {
            return null;
        }

        return [
            'importsCount' => $this->toInt($row['imports_count'] ?? 0),
            'successfulImportsCount' => $this->toInt($row['successful_imports_count'] ?? 0),
            'failedImportsCount' => $this->toInt($row['failed_imports_count'] ?? 0),
            'recordsTotal' => $this->toInt($row['records_total'] ?? 0),
            'recordsRejected' => $this->toInt($row['records_rejected'] ?? 0),
        ];
    }

    private function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function dayBounds(\DateTimeImmutable $day): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $normalized = new \DateTimeImmutable($day->format('Y-m-d'), $tz);
        $from = $normalized->setTime(0, 0);
        $to = $from->modify('+1 day');

        return [$from, $to];
    }
}
