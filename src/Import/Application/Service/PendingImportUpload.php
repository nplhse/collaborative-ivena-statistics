<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

/**
 * A file uploaded in the background of the import form (#977), waiting for the form submission.
 */
final readonly class PendingImportUpload
{
    public function __construct(
        public string $token,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public \DateTimeImmutable $uploadedAt,
    ) {
    }
}
