<?php

namespace App\Import\Application\Exception;

final class InvalidEnumException extends ImportException
{
    public static function forField(string $field, ?string $value): self
    {
        return new self(
            message: sprintf('Invalid enum value for "%s"', $field),
            field: $field,
            value: $value,
            codeStr: 'INVALID_ENUM'
        );
    }
}
