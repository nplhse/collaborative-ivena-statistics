<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Display\RecordSection;
use App\Encounter\Contract\Display\RecordSectionProviderInterface;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use Doctrine\DBAL\Connection;

/**
 * Record section of the test-only schema `probe`: shown for encounters whose case pseudonym is the one of
 * the case label `probe-section`, with one query, like a schema that reads its own table.
 */
final readonly class ProbeRecordSectionProvider implements RecordSectionProviderInterface
{
    public const string CASE = 'probe-section';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function sectionsForEncounter(int $encounterId): array
    {
        $caseKey = $this->connection->fetchOne('SELECT case_key FROM encounter WHERE id = ?', [$encounterId]);
        if (EncounterFileBuilder::caseKey(self::CASE) !== $caseKey) {
            return [];
        }

        return [new RecordSection('probe', '@EncounterProbe/record_section.html.twig', ['encounter_id' => $encounterId])];
    }
}
