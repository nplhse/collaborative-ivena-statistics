<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis;

final class TransformerHintGenerator
{
    public function generate(string $field, string $rejectedValue, string $reason): string
    {
        $reasonLower = strtolower($reason);

        if ($this->isReferenceNotFound($reasonLower)) {
            return sprintf(
                "Add mapping/normalizer for field '%s' value '%s'",
                $field,
                $rejectedValue,
            );
        }

        if ($this->isEmptyValue($rejectedValue, $reasonLower)) {
            return sprintf("Handle empty value for field '%s'", $field);
        }

        if ($this->isDateOrNumberFormatIssue($reasonLower)) {
            return 'Add parser/normalizer for date/number format';
        }

        if (str_contains($reasonLower, 'invalid_enum') || str_contains($reasonLower, 'invalid enum')) {
            return sprintf(
                "Add enum mapping/normalizer for field '%s' value '%s'",
                $field,
                $rejectedValue,
            );
        }

        return 'Inspect reject reason and add transformer if appropriate';
    }

    private function isReferenceNotFound(string $reasonLower): bool
    {
        return str_contains($reasonLower, 'ref_not_found')
            || str_contains($reasonLower, 'reference not found')
            || str_contains($reasonLower, 'not found for');
    }

    private function isEmptyValue(string $rejectedValue, string $reasonLower): bool
    {
        if ('(empty)' === $rejectedValue) {
            return true;
        }

        return str_contains($reasonLower, 'not be blank')
            || str_contains($reasonLower, 'should not be null')
            || str_contains($reasonLower, 'cannot be empty')
            || str_contains($reasonLower, 'is required');
    }

    private function isDateOrNumberFormatIssue(string $reasonLower): bool
    {
        return str_contains($reasonLower, 'invalid_date')
            || str_contains($reasonLower, 'invalid date')
            || str_contains($reasonLower, 'datetime')
            || str_contains($reasonLower, 'd.m.y')
            || str_contains($reasonLower, 'date/time')
            || str_contains($reasonLower, 'number format');
    }
}
