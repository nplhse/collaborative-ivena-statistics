<?php

declare(strict_types=1);

namespace App\Import\Domain\Validation\Constraints;

use App\Import\Application\Service\ImportUploadGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ImportSourceFileValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ImportUploadGuard $importUploadGuard,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ImportSourceFile) {
            throw new UnexpectedTypeException($constraint, ImportSourceFile::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        $messageKey = $this->importUploadGuard->resolveRejectionMessageKey($value);
        if (null === $messageKey) {
            return;
        }

        $message = match ($messageKey) {
            'validation.import.excel_rejected' => $constraint->excelRejectedMessage,
            'validation.import.file_extensions' => $constraint->unsupportedExtensionMessage,
            default => $constraint->invalidMimeMessage,
        };

        $this->context
            ->buildViolation($message)
            ->addViolation();
    }
}
