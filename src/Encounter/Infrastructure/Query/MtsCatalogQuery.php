<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\MtsCatalogReaderInterface;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\DBAL\Connection;

/**
 * Reads the MTS catalog tables (ADR 032) for MtsCatalog.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of MtsCatalogReaderInterface.
 */
final readonly class MtsCatalogQuery implements MtsCatalogReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function entries(MtsCatalogKind $kind): array
    {
        /** @var list<array{code: string, german_name: string, english_name: ?string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT code, german_name, english_name, status FROM %s ORDER BY code',
            MtsCatalogKind::Diagram === $kind ? 'encounter_mts_diagram' : 'encounter_mts_indicator',
        ));

        return array_map(static fn (array $row): array => [
            'code' => $row['code'],
            'german_name' => $row['german_name'],
            'english_name' => $row['english_name'],
            'status' => MtsCatalogStatus::from($row['status']),
        ], $rows);
    }

    #[\Override]
    public function aliases(MtsCatalogKind $kind): array
    {
        /** @var array<string, string> $aliases */
        $aliases = $this->connection->fetchAllKeyValue(
            'SELECT code, target_code FROM encounter_mts_alias WHERE kind = :kind ORDER BY code',
            ['kind' => $kind->value],
        );

        return $aliases;
    }
}
