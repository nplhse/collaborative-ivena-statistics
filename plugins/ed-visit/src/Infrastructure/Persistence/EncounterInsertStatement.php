<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Infrastructure\Persistence;

use App\Encounter\Domain\Entity\Encounter;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two inserts of a new ED visit, built from the entities' Doctrine mapping so that columns and
 * conversions always follow the entities (no second column list to keep in sync):
 *
 * - the encounter (`INSERT … RETURNING id`);
 * - the visit with that encounter id, `ON CONFLICT DO NOTHING` on its identity
 *   `(hospital_id, key_generation, visit_fingerprint)` (ADR 029). Under READ COMMITTED a second inserter of
 *   the same visit waits for the first transaction and then inserts nothing.
 *
 * EncounterRepository runs both in one savepoint and rolls it back for a duplicate, so no encounter stays
 * without its visit.
 */
final readonly class EncounterInsertStatement
{
    public const string CONFLICT_TARGET = '(hospital_id, key_generation, visit_fingerprint)';

    /**
     * @param list<mixed> $params PHP values in column order
     * @param list<Type>  $types  DBAL types converting $params to database values
     */
    private function __construct(
        public string $sql,
        public array $params,
        public array $types,
    ) {
    }

    public static function forEncounter(EntityManagerInterface $em, Encounter $encounter): self
    {
        [$columns, $params, $types] = self::columns($em, Encounter::class, $encounter);

        return new self(
            sprintf('INSERT INTO encounter (%s) VALUES (%s) RETURNING id', implode(', ', $columns), implode(', ', array_fill(0, \count($columns), '?'))),
            $params,
            $types,
        );
    }

    public static function forVisit(EntityManagerInterface $em, EdVisit $visit, int $encounterId): self
    {
        [$columns, $params, $types] = self::columns($em, EdVisit::class, $visit, ['encounter_id' => $encounterId]);

        return new self(
            sprintf(
                'INSERT INTO ed_visit (%s) VALUES (%s) ON CONFLICT %s DO NOTHING RETURNING encounter_id',
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
                self::CONFLICT_TARGET,
            ),
            $params,
            $types,
        );
    }

    /**
     * Refreshes a known visit from a reprocessed file (#1300): every ED value of the row; its identity (encounter,
     * uid, hospital, key generation, fingerprint), owning import and creation time stay.
     */
    public static function forVisitUpdate(EntityManagerInterface $em, EdVisit $visit, int $encounterId): self
    {
        [$columns, $params, $types] = self::columns($em, EdVisit::class, $visit, ['encounter_id' => $encounterId]);
        $keep = ['encounter_id', 'visit_uid', 'hospital_id', 'key_generation', 'visit_fingerprint', 'import_id', 'created_at'];

        $assignments = [];
        $setParams = [];
        $setTypes = [];
        foreach ($columns as $index => $column) {
            if (\in_array($column, $keep, true)) {
                continue;
            }
            $assignments[] = $column.' = ?';
            $setParams[] = $params[$index];
            $setTypes[] = $types[$index];
        }

        return new self(
            sprintf('UPDATE ed_visit SET %s WHERE encounter_id = ?', implode(', ', $assignments)),
            [...$setParams, $encounterId],
            [...$setTypes, Type::getType(Types::INTEGER)],
        );
    }

    /**
     * @param class-string       $class
     * @param array<string, int> $joinColumnValues values of owning to-one join columns by column name, instead of the related object's id
     *
     * @return array{list<string>, list<mixed>, list<Type>}
     */
    private static function columns(EntityManagerInterface $em, string $class, object $entity, array $joinColumnValues = []): array
    {
        $metadata = $em->getClassMetadata($class);

        $columns = [];
        $params = [];
        $types = [];

        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($metadata->isIdentifier($field)) {
                continue; // identity column
            }

            $value = $metadata->getFieldValue($entity, $field);
            $columns[] = $mapping->columnName;
            $params[] = $value instanceof \BackedEnum ? $value->value : $value;
            $types[] = Type::getType($mapping->type);
        }

        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }

            $joinColumn = $mapping->joinColumns[0];
            $columns[] = $joinColumn->name;
            if (\array_key_exists($joinColumn->name, $joinColumnValues)) {
                $params[] = $joinColumnValues[$joinColumn->name];
                $types[] = Type::getType(Types::INTEGER);

                continue;
            }

            $related = $metadata->getFieldValue($entity, $field);
            $targetMetadata = $em->getClassMetadata($mapping->targetEntity);
            // The entity's own id getter: reading the id of a reference that is not loaded yet does not load it
            // (reflection on such a lazy object may see no id).
            $params[] = \is_object($related) && method_exists($related, 'getId') ? $related->getId() : null;
            $types[] = Type::getType($targetMetadata->getTypeOfField($targetMetadata->getSingleIdentifierFieldName()) ?? 'integer');
        }

        return [$columns, $params, $types];
    }
}
