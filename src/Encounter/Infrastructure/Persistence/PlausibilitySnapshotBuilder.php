<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Persistence;

use App\Encounter\Application\Contract\PlausibilitySnapshotBuilderInterface;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds a draft snapshot from the curated problem areas in bulk (ADR 033), with the rule of the seed migration
 * Version20261113235145, and writes the cells the learning job estimated (#1155). Run inside the caller's transaction.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of PlausibilitySnapshotBuilderInterface.
 */
final readonly class PlausibilitySnapshotBuilder implements PlausibilitySnapshotBuilderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function rebuildFromAreas(PlausibilitySnapshot $draft): void
    {
        if (!$draft->isDraft() || null === $draft->getId()) {
            throw new \LogicException('Only a stored draft snapshot can be rebuilt.');
        }

        $parameters = ['id' => $draft->getId()];
        $this->connection->executeStatement('DELETE FROM encounter_plausibility_cell WHERE snapshot_id = :id', $parameters);
        $this->connection->executeStatement('DELETE FROM encounter_plausibility_snapshot_code WHERE snapshot_id = :id', $parameters);

        $this->connection->executeStatement(
            'INSERT INTO encounter_plausibility_snapshot_code (snapshot_id, pzc, area_code)
             SELECT :id, c.pzc, a.code FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area a ON a.id = c.area_id',
            $parameters,
        );
        $this->connection->executeStatement(
            "INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives)
             SELECT :id, NULL, a.code, d.code, NULL, r.points, 'curated', 0, 0
             FROM encounter_plausibility_area_diagram r
             JOIN encounter_plausibility_area a ON a.id = r.area_id
             JOIN encounter_mts_diagram d ON d.id = r.diagram_id",
            $parameters,
        );
        $this->connection->executeStatement(
            "INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives)
             SELECT :id, c.pzc, NULL, d.code, NULL, r.points, 'curated', 0, 0
             FROM encounter_plausibility_area_code c
             JOIN encounter_plausibility_area_diagram r ON r.area_id = c.area_id
             JOIN encounter_mts_diagram d ON d.id = r.diagram_id",
            $parameters,
        );
    }

    #[\Override]
    public function writeLearnedCells(PlausibilitySnapshot $draft, array $cells): void
    {
        if (!$draft->isDraft() || null === $draft->getId()) {
            throw new \LogicException('Only a stored draft snapshot gets learned cells.');
        }

        foreach ($cells as $cell) {
            $values = [
                'id' => $draft->getId(),
                'pzc' => $cell->pzc,
                'area' => $cell->areaCode,
                'diagram' => $cell->diagramCode,
                'points' => $cell->points,
                'source' => $cell->source->value,
                'positives' => $cell->positives,
                'negatives' => $cell->negatives,
            ];
            $updated = $this->connection->executeStatement(
                'UPDATE encounter_plausibility_cell SET points = :points, source = :source, positives = :positives, negatives = :negatives
                 WHERE snapshot_id = :id AND diagram_code = :diagram AND indicator_code IS NULL
                   AND pzc IS NOT DISTINCT FROM CAST(:pzc AS SMALLINT) AND area_code IS NOT DISTINCT FROM CAST(:area AS VARCHAR)',
                $values,
            );
            if (0 === $updated) {
                $this->connection->executeStatement(
                    'INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives)
                     VALUES (:id, CAST(:pzc AS SMALLINT), CAST(:area AS VARCHAR), :diagram, NULL, :points, :source, :positives, :negatives)',
                    $values,
                );
            }
        }
    }
}
