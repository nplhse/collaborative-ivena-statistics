<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Infrastructure\Audit\Attribute\Audited;
use App\Shared\Infrastructure\Audit\Attribute\AuditIgnore;
use App\Shared\Infrastructure\Audit\Attribute\AuditSensitive;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

final class AuditFieldRules
{
    private const array AUTOMATIC_METADATA_FIELDS = ['createdAt', 'updatedAt', 'createdBy', 'updatedBy'];

    /**
     * @param class-string $class
     */
    public function isClassAudited(string $class): bool
    {
        if (!class_exists($class) && !enum_exists($class)) {
            return false;
        }

        $r = new \ReflectionClass($class);

        return [] !== $r->getAttributes(Audited::class);
    }

    /**
     * @param class-string $rootFqcn
     */
    public function shouldSkipField(string $rootFqcn, string $fieldName): bool
    {
        $rootProperty = explode('.', $fieldName, 2)[0];
        if (\in_array($rootProperty, self::AUTOMATIC_METADATA_FIELDS, true)) {
            return true;
        }

        return $this->fieldHasAttribute($rootFqcn, $fieldName, AuditIgnore::class);
    }

    /**
     * @param class-string $rootFqcn
     */
    public function isSensitiveField(string $rootFqcn, string $fieldName): bool
    {
        return $this->fieldHasAttribute($rootFqcn, $fieldName, AuditSensitive::class);
    }

    /**
     * @param class-string $rootFqcn
     * @param class-string $attributeFqcn
     */
    private function fieldHasAttribute(string $rootFqcn, string $fieldName, string $attributeFqcn): bool
    {
        $parts = explode('.', $fieldName, 2);
        $propertyName = $parts[0];
        $rest = $parts[1] ?? null;

        if (!class_exists($rootFqcn)) {
            return false;
        }

        $r = new \ReflectionClass($rootFqcn);

        if (!$r->hasProperty($propertyName)) {
            return false;
        }

        $prop = $r->getProperty($propertyName);

        if ([] !== $prop->getAttributes($attributeFqcn)) {
            return true;
        }

        if (null === $rest || '' === $rest) {
            return false;
        }

        $targetClass = $this->resolvePropertyClass($prop);
        if (null === $targetClass) {
            return false;
        }

        return $this->fieldHasAttribute($targetClass, $rest, $attributeFqcn);
    }

    /**
     * @return class-string|null
     */
    private function resolvePropertyClass(\ReflectionProperty $prop): ?string
    {
        $t = $prop->getType();
        if (!$t instanceof \ReflectionNamedType || $t->isBuiltin()) {
            return null;
        }

        $n = $t->getName();
        if (!class_exists($n) && !enum_exists($n)) {
            return null;
        }

        return $n;
    }

    /**
     * @param ClassMetadata<object> $meta
     *
     * @return list<string>
     */
    public function fieldAndToOneAssociationNames(ClassMetadata $meta): array
    {
        $names = $meta->getFieldNames();

        foreach ($meta->getAssociationMappings() as $assocName => $mapping) {
            if ($mapping instanceof ToOneOwningSideMapping) {
                $names[] = $assocName;
            }
        }

        return array_values(array_unique($names));
    }
}
