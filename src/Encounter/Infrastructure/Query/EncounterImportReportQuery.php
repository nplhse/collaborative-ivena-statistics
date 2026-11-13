<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterImportReportInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Row rejects and suspected duplicates of one encounter import (#1048). Reject rows of encounter
 * imports only hold profile and profile version (EncounterFileImporter), so nothing here can show
 * a cell value.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterImportReportInterface.
 */
final readonly class EncounterImportReportQuery implements EncounterImportReportInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function rejects(int $importId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT line_number, messages, "row" AS context FROM import_reject WHERE import_id = :import ORDER BY line_number, id LIMIT :limit',
            ['import' => $importId, 'limit' => $limit],
            ['import' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $rejects = [];
        foreach ($rows as $row) {
            $messages = json_decode((string) $row['messages'], true);
            $context = json_decode((string) $row['context'], true);
            $profileVersion = \is_array($context) ? ($context['profile_version'] ?? null) : null;
            $rejects[] = [
                'line' => null === $row['line_number'] ? null : (int) $row['line_number'],
                'errors' => \is_array($messages) ? array_values(array_map(strval(...), $messages)) : [],
                'profile_version' => \is_string($profileVersion) ? $profileVersion : null,
            ];
        }

        return $rejects;
    }

    #[\Override]
    public function suspectedDuplicates(int $importId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT sd.line_number, sd.anchor_difference_minutes, sd.anchor_source, sd.existing_anchor_source, i.id AS existing_import_id, i.name AS existing_import_name
FROM encounter_suspected_duplicate sd
JOIN encounter existing ON existing.id = sd.existing_encounter_id
JOIN import i ON i.id = existing.import_id
WHERE sd.import_id = :import
ORDER BY sd.line_number, sd.id
LIMIT :limit
SQL,
            ['import' => $importId, 'limit' => $limit],
            ['import' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'line' => (int) $row['line_number'],
            'difference_minutes' => (int) $row['anchor_difference_minutes'],
            'anchor_source' => (string) $row['anchor_source'],
            'existing_anchor_source' => (string) $row['existing_anchor_source'],
            'existing_import_id' => (int) $row['existing_import_id'],
            'existing_import_name' => (string) $row['existing_import_name'],
        ], $rows);
    }
}
