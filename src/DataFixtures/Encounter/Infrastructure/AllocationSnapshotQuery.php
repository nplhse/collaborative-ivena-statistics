<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Infrastructure;

use App\DataFixtures\Encounter\Application\AllocationSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The allocations of a hospital in a period, as input of the encounter generator (#1052).
 */
final readonly class AllocationSnapshotQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param \DateTimeImmutable $from  inclusive
     * @param \DateTimeImmutable $until exclusive
     *
     * @return list<AllocationSnapshot> ordered by arrival and id
     */
    public function forHospital(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<array{id: int|string, arrival_at: string, age: int|string, gender: string, urgency: int|string|null, is_with_physician: bool|string, is_work_accident: bool|string, transport_type: ?string, department: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT a.id, a.arrival_at, a.age, a.gender, a.urgency, a.is_with_physician, a.is_work_accident, a.transport_type, d.name AS department
FROM allocation a
LEFT JOIN department d ON d.id = a.department_id
WHERE a.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :until
ORDER BY a.arrival_at, a.id
SQL,
            ['hospital' => $hospitalId, 'from' => $from, 'until' => $until],
            ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'until' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (array $row): AllocationSnapshot => new AllocationSnapshot(
            id: (int) $row['id'],
            arrivalAt: new \DateTimeImmutable($row['arrival_at']),
            age: (int) $row['age'],
            gender: $row['gender'],
            urgency: (int) ($row['urgency'] ?? 3),
            isWithPhysician: self::flag($row['is_with_physician']),
            isWorkAccident: self::flag($row['is_work_accident']),
            isAirTransport: 'A' === $row['transport_type'],
            departmentName: $row['department'],
        ), $rows);
    }

    private static function flag(bool|string $value): bool
    {
        return true === $value || 't' === $value || '1' === $value;
    }
}
