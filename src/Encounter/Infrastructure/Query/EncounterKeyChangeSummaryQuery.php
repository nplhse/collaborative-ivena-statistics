<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterKeyChangeSummaryReaderInterface;
use App\Encounter\Contract\KeyChange\KeyChangeFiguresProviderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Counts shown on the key change page (ADR 027): whether a key change is in progress and what a key
 * change with "delete" would remove: the hospital's encounters and imports (#1053), plus the links and
 * decisions the schemas report (KeyChangeFiguresProviderInterface, the `ed_visit` match review, #1375).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterKeyChangeSummaryReaderInterface.
 */
final readonly class EncounterKeyChangeSummaryQuery implements EncounterKeyChangeSummaryReaderInterface
{
    /**
     * @param iterable<KeyChangeFiguresProviderInterface> $figures
     */
    public function __construct(
        private Connection $connection,
        #[AutowireIterator(KeyChangeFiguresProviderInterface::TAG)]
        private iterable $figures = [],
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

        $links = 0;
        $decisions = 0;
        foreach ($this->figures as $provider) {
            $figures = $provider->figuresForHospital($hospitalId);
            $links += $figures->links;
            $decisions += $figures->decisions;
        }

        return [
            'key_change_in_progress' => false !== $inProgress && (bool) $inProgress,
            'encounters' => $encounters,
            'imports' => $imports,
            'links' => $links,
            'decisions' => $decisions,
        ];
    }
}
