<?php

declare(strict_types=1);

namespace App\Kpi\Infrastructure\Query;

use App\Kpi\Application\Contract\KpiPeriodReaderInterface;
use Doctrine\DBAL\Connection;

final readonly class KpiPeriodQuery implements KpiPeriodReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function globalTotals(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(k.imports_count), 0) AS imports_count,
                    COALESCE(SUM(k.records_total), 0) AS records_total,
                    COALESCE(SUM(k.records_rejected), 0) AS records_rejected
                FROM kpi_daily k
                WHERE k.hospital_id IS NULL
                  AND k.date >= :from
                  AND k.date < :to
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $toExclusive->format('Y-m-d')],
        );

        return [
            'importsCount' => $this->toInt($row['imports_count'] ?? 0),
            'recordsTotal' => $this->toInt($row['records_total'] ?? 0),
            'recordsRejected' => $this->toInt($row['records_rejected'] ?? 0),
        ];
    }

    #[\Override]
    public function countActiveParticipatingHospitals(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        return $this->toInt($this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT k.hospital_id)
                FROM kpi_daily k
                INNER JOIN hospital h ON h.id = k.hospital_id
                WHERE h.is_participating = true
                  AND k.successful_imports_count > 0
                  AND k.date >= :from
                  AND k.date < :to
                SQL,
            ['from' => $from->format('Y-m-d'), 'to' => $toExclusive->format('Y-m-d')],
        ));
    }

    #[\Override]
    public function countParticipatingHospitals(): int
    {
        return $this->toInt($this->connection->fetchOne('SELECT COUNT(*) FROM hospital WHERE is_participating = true'));
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
}
