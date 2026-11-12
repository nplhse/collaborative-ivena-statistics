<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterKeyChangeSummaryReaderInterface;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Counts shown on the key change page (ADR 026): whether a key change is in progress and what a key
 * change with "delete" would remove: visits, imports, effective IVENA links (automatic or confirmed) and
 * manual decisions (confirmed or rejected) of the hospital's visits (#1053).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterKeyChangeSummaryReaderInterface.
 */
final readonly class EncounterKeyChangeSummaryQuery implements EncounterKeyChangeSummaryReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{key_change_in_progress: bool, encounters: int, imports: int, links: int, decisions: int}
     */
    #[\Override]
    public function forHospital(int $hospitalId): array
    {
        $inProgress = $this->connection->fetchOne(
            'SELECT key_change_in_progress FROM encounter_key_state WHERE hospital_id = ?',
            [$hospitalId],
        );
        $encounters = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM encounter WHERE hospital_id = ?', [$hospitalId]);
        $imports = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM import i INNER JOIN encounter_import ei ON ei.import_id = i.id WHERE i.hospital_id = ?',
            [$hospitalId],
        );

        return [
            'key_change_in_progress' => false !== $inProgress && (bool) $inProgress,
            'encounters' => $encounters,
            'imports' => $imports,
            'links' => $this->countMatches($hospitalId, MatchStatus::effective()),
            'decisions' => $this->countMatches($hospitalId, [MatchStatus::Confirmed, MatchStatus::Rejected]),
        ];
    }

    /**
     * @param list<MatchStatus> $statuses
     */
    private function countMatches(int $hospitalId, array $statuses): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM encounter_match WHERE hospital_id = :hospital AND status IN (:statuses)',
            ['hospital' => $hospitalId, 'statuses' => array_map(static fn (MatchStatus $status): string => $status->value, $statuses)],
            ['statuses' => ArrayParameterType::STRING],
        );
    }
}
