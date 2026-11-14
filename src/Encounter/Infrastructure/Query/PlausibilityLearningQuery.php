<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\PlausibilityLearningReaderInterface;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Eligible pairs and manual decisions for the learning job of the plausibility table (ADR 033, #1155).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of PlausibilityLearningReaderInterface.
 */
final readonly class PlausibilityLearningQuery implements PlausibilityLearningReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function eligiblePairs(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        /** @var list<array{hospital_id: int|string, allocation_id: int|string, encounter_id: int|string, day: string, from_bonus: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT m.hospital_id, m.allocation_id, m.encounter_id, TO_CHAR(a.arrival_at, 'YYYY-MM-DD') AS day,
       (m.status = :confirmed AND m.plausibility_decisive) AS from_bonus
FROM encounter_match m
JOIN allocation a ON a.id = m.allocation_id
WHERE (m.status = :confirmed OR (m.status = :automatic AND NOT m.plausibility_decisive))
  AND a.arrival_at >= COALESCE(CAST(:from AS TIMESTAMP), '-infinity'::timestamp)
  AND a.arrival_at < COALESCE(CAST(:to AS TIMESTAMP), 'infinity'::timestamp)
ORDER BY m.hospital_id, a.arrival_at, m.allocation_id
SQL,
            [
                'confirmed' => MatchStatus::Confirmed->value,
                'automatic' => MatchStatus::AutoAccepted->value,
                'from' => $from,
                'to' => $to,
            ],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (array $row): array => [
            'hospital_id' => (int) $row['hospital_id'],
            'allocation_id' => (int) $row['allocation_id'],
            'encounter_id' => (int) $row['encounter_id'],
            'day' => $row['day'],
            'from_bonus' => (bool) $row['from_bonus'],
        ], $rows);
    }

    #[\Override]
    public function decidedPairs(int $hospitalId): array
    {
        /** @var list<array{allocation_id: int|string, encounter_id: int|string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT allocation_id, encounter_id, status FROM encounter_match WHERE hospital_id = :hospital AND status IN (:confirmed, :rejected)',
            ['hospital' => $hospitalId, 'confirmed' => MatchStatus::Confirmed->value, 'rejected' => MatchStatus::Rejected->value],
        );

        $pairs = [];
        foreach ($rows as $row) {
            $pairs[$row['allocation_id'].':'.$row['encounter_id']] = MatchStatus::Confirmed->value === $row['status'];
        }

        return $pairs;
    }
}
