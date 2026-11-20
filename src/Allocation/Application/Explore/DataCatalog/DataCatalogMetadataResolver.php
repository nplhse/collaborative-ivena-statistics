<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\CatalogCode;
use App\Allocation\Application\DTO\DataCatalog\CatalogGroup;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

/**
 * Reads table, column, type, nullability, codes and reference targets of documented variables from
 * the Doctrine mapping. Loading class metadata runs no database query.
 */
final readonly class DataCatalogMetadataResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(DataObjectDefinition $definition): CatalogObject
    {
        $metadata = $this->entityManager->getClassMetadata($definition->entityClass);

        $groups = array_map(
            fn (VariableGroupDefinition $group): CatalogGroup => new CatalogGroup(
                $group->key,
                array_map(
                    fn (VariableDefinition $variable): CatalogVariable => $this->variable($metadata, $variable, $definition->translationDomain),
                    $group->variables,
                ),
            ),
            $definition->groups,
        );

        return new CatalogObject(
            $definition->key,
            $definition->entityClass,
            $definition->translationDomain,
            $definition->icon,
            $metadata->getTableName(),
            $definition->contributedByPlugin,
            $groups,
        );
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function variable(ClassMetadata $metadata, VariableDefinition $variable, string $domain): CatalogVariable
    {
        $segments = explode('.', $variable->property);
        $nullable = false;

        while ([] !== $segments) {
            $path = implode('.', $segments);
            if ($metadata->hasField($path)) {
                $field = $metadata->getFieldMapping($path);
                $enum = $field->enumType;

                return new CatalogVariable(
                    $variable->key,
                    $domain,
                    DataCatalogType::fromDoctrine($field->type, null !== $enum),
                    $nullable || true === $field->nullable,
                    $metadata->getTableName(),
                    $field->columnName,
                    null === $enum ? [] : $this->codes($enum, $variable->codeLabels),
                    $variable->range,
                );
            }

            $name = array_shift($segments);
            $association = $metadata->hasAssociation($name) ? $metadata->getAssociationMapping($name) : null;
            if (!$association instanceof ToOneOwningSideMapping) {
                throw new \LogicException(sprintf('Data catalog variable "%s": "%s" is no mapped field or owning to-one association of %s.', $variable->key, $name, $metadata->getName()));
            }

            $joinColumn = $association->joinColumns[0];
            $nullable = $nullable || false !== $joinColumn->nullable;
            $target = $this->entityManager->getClassMetadata($association->targetEntity);

            if ([] === $segments) {
                return new CatalogVariable(
                    $variable->key,
                    $domain,
                    DataCatalogType::REFERENCE,
                    $nullable,
                    $metadata->getTableName(),
                    $joinColumn->name,
                    referenceClass: $association->targetEntity,
                    referenceTable: $target->getTableName(),
                );
            }

            $metadata = $target;
        }

        throw new \LogicException(sprintf('Data catalog variable "%s" has an empty property.', $variable->key));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<CatalogCode>
     */
    private function codes(string $enum, ?CodeLabels $labels): array
    {
        return array_map(
            static function (\BackedEnum $case) use ($labels): CatalogCode {
                $code = (string) $case->value;
                if (!$labels instanceof CodeLabels) {
                    return new CatalogCode($code, null, null);
                }

                if (null !== $labels->prefix) {
                    return new CatalogCode($code, $labels->prefix.$code, $labels->domain);
                }

                if (!method_exists($case, 'label')) {
                    throw new \LogicException(sprintf('%s has no label() method.', $case::class));
                }

                return new CatalogCode($code, (string) $case->label(), $labels->domain);
            },
            $enum::cases(),
        );
    }
}
