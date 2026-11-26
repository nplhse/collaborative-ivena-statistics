<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Domain\Entity\Import;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final readonly class ImportSourceFileDownloadService
{
    /** @var list<string> */
    private const array DOWNLOAD_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private ImportFileStorage $fileStorage,
    ) {
    }

    public function createDownloadResponse(Import $import): BinaryFileResponse
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new ImportSourceFileNotFoundException(0);
        }

        $storedPath = $import->getFilePath();
        if (null === $storedPath || '' === trim($storedPath)) {
            throw new ImportSourceFileNotFoundException($importId);
        }

        try {
            $absolutePath = $this->fileStorage->resolve($storedPath);
        } catch (ImportFilePathOutsideBaseException) {
            throw new ImportSourceFileNotFoundException($importId);
        }

        if (!\is_file($absolutePath)) {
            throw new ImportSourceFileNotFoundException($importId);
        }

        $filename = $this->buildDownloadFilename($import, $storedPath);
        // The stored type comes from the upload request; only known spreadsheet types are passed on.
        $mimeType = $import->getFileMimeType();
        if (!\in_array($mimeType, self::DOWNLOAD_MIME_TYPES, true)) {
            $mimeType = 'application/octet-stream';
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    private function buildDownloadFilename(Import $import, string $storedPath): string
    {
        $extension = strtolower(trim((string) $import->getFileExtension()));
        if ('' === $extension) {
            $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        }

        $name = trim((string) $import->getName());
        if ('' !== $name) {
            $sanitized = $this->sanitizeFilenameSegment($name);

            return '' !== $extension ? $sanitized.'.'.$extension : $sanitized;
        }

        return basename(str_replace('\\', '/', $storedPath));
    }

    private function sanitizeFilenameSegment(string $value): string
    {
        $sanitized = preg_replace('/[^\w\-. ]+/u', '_', $value) ?? $value;
        $sanitized = trim($sanitized, "._ \t\n\r\0\x0B");

        if ('' === $sanitized) {
            return 'import';
        }

        return $sanitized;
    }
}
