<?php

declare(strict_types=1);

namespace App\Import\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ImportSourceFile extends Constraint
{
    public string $excelRejectedMessage = 'validation.import.excel_rejected';

    public string $unsupportedExtensionMessage = 'validation.import.file_extensions';

    public string $invalidMimeMessage = 'validation.import.file_mime_types';

    #[\Override]
    public function validatedBy(): string
    {
        return ImportSourceFileValidator::class;
    }
}
