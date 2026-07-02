<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Domain\Entity\Import;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final readonly class ImportSourceFileDownloadService
{
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

        $absolutePath = $this->fileStorage->resolve($storedPath);
        if (!\is_file($absolutePath)) {
            throw new ImportSourceFileNotFoundException($importId);
        }

        $filename = $this->buildDownloadFilename($import, $storedPath);
        $mimeType = $import->getFileMimeType() ?? 'text/csv';

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $mimeType);
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
