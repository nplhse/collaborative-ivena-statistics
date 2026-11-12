<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterKeyChangeSummaryReaderInterface;
use App\Encounter\Domain\Entity\EncounterKeyState;
use Doctrine\DBAL\Connection;

/**
 * Counts shown on the key change page (ADR 026): the hospital's key generation and what a key change
 * with "delete" would remove. Links and review decisions are counted once they exist (#1053, #1054).
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
     * @return array{generation: int, key_change_in_progress: bool, encounters: int, imports: int}
     */
    #[\Override]
    public function forHospital(int $hospitalId): array
    {
        $state = $this->connection->fetchAssociative(
            'SELECT key_generation, key_change_in_progress FROM encounter_key_state WHERE hospital_id = ?',
            [$hospitalId],
        );
        $encounters = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM encounter WHERE hospital_id = ?', [$hospitalId]);
        $imports = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM import i INNER JOIN encounter_import ei ON ei.import_id = i.id WHERE i.hospital_id = ?',
            [$hospitalId],
        );

        return [
            'generation' => false === $state ? EncounterKeyState::FIRST_GENERATION : (int) $state['key_generation'],
            'key_change_in_progress' => false !== $state && (bool) $state['key_change_in_progress'],
            'encounters' => $encounters,
            'imports' => $imports,
        ];
    }
}
