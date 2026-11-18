<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterImportReportInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Row rejects of one encounter import (#1048); suspected duplicates come from the schema (#1375). Reject rows of encounter
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
}
