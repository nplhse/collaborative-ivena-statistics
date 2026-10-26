<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Package rules of a reference catalog package that field constraints cannot see (ADR 020).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidReferenceCatalogPackage extends Constraint
{
    /**
     * @param array<int, string>|null $groups
     */
    public function __construct(
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
