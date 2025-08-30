<?php

namespace App\Service\Import\Exception;

final class ReferenceNotFoundException extends ImportException
{
    public static function forField(string $field, ?string $value): self
    {
        return new self(
            message: sprintf('Reference not found for "%s"', $field),
            field: $field,
            value: $value,
            codeStr: 'REF_NOT_FOUND'
        );
    }
}
