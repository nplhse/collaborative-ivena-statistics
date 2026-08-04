<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form\Validation;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ExportDateRangeValid extends Constraint
{
    /**
     * @param array<int, string>|null $groups
     */
    public function __construct(
        public string $message = 'error.export.date_range_invalid',
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

    #[\Override]
    public function validatedBy(): string
    {
        return ExportDateRangeValidValidator::class;
    }
}
