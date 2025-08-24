<?php

namespace App\Service\Import\Exception;

final class InvalidDateException extends ImportException
{
    public static function forField(string $field, ?string $value): self
    {
        return new self(
            message: sprintf('Invalid date/time for "%s" (expected d.m.Y H:i)', $field),
            field: $field,
            value: $value,
            codeStr: 'INVALID_DATE'
        );
    }
}
