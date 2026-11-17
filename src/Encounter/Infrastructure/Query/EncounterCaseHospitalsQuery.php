<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterCaseHospitalsReaderInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterCaseHospitalsReaderInterface.
 */
final readonly class EncounterCaseHospitalsQuery implements EncounterCaseHospitalsReaderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function hospitals(?array $hospitalIds): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        $sql = 'SELECT h.id, h.name FROM hospital h WHERE '
            .(null === $hospitalIds ? 'EXISTS (SELECT 1 FROM encounter e WHERE e.hospital_id = h.id)' : 'h.id IN (:ids)')
            .' ORDER BY h.name, h.id';

        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['ids' => $hospitalIds ?? []], ['ids' => ArrayParameterType::INTEGER]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $rows);
    }
}
