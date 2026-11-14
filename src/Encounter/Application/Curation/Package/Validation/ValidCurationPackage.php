<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Package rules of an encounter curation package that field constraints cannot see (#1198).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCurationPackage extends Constraint
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
