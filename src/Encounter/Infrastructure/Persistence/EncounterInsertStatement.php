<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Persistence;

use App\Encounter\Domain\Entity\Encounter;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `INSERT … ON CONFLICT DO NOTHING` for one new encounter, built from the entity's Doctrine mapping so
 * that columns and conversions always follow the entity (no second column list to keep in sync).
 *
 * The conflict target is the visit identity `(hospital_id, key_generation, visit_fingerprint)`
 * (ADR 029). Under READ COMMITTED a second inserter of the same visit waits for the first
 * transaction and then inserts nothing, so parallel imports cannot create a visit twice.
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

    public static function for(EntityManagerInterface $em, Encounter $encounter): self
    {
        $metadata = $em->getClassMetadata(Encounter::class);

        $columns = [];
        $params = [];
        $types = [];

        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($metadata->isIdentifier($field)) {
                continue; // identity column
            }

            $value = $metadata->getFieldValue($encounter, $field);
            $columns[] = $mapping->columnName;
            $params[] = $value instanceof \BackedEnum ? $value->value : $value;
            $types[] = Type::getType($mapping->type);
        }

        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue;
            }

            $related = $metadata->getFieldValue($encounter, $field);
            $targetMetadata = $em->getClassMetadata($mapping->targetEntity);
            $joinColumn = $mapping->joinColumns[0];

            $columns[] = $joinColumn->name;
            $params[] = \is_object($related) ? $targetMetadata->getFieldValue($related, $targetMetadata->getSingleIdentifierFieldName()) : null;
            $types[] = Type::getType($targetMetadata->getTypeOfField($targetMetadata->getSingleIdentifierFieldName()) ?? 'integer');
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT %s DO NOTHING RETURNING id',
            $metadata->getTableName(),
            implode(', ', $columns),
            implode(', ', array_fill(0, \count($columns), '?')),
            self::CONFLICT_TARGET,
        );

        return new self($sql, $params, $types);
    }
}
