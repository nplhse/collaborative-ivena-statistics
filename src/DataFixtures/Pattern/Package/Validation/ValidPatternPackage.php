<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Package rules of a pattern package that the structure check cannot see (ADR 022).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidPatternPackage extends Constraint
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
