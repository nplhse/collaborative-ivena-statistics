<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\ImportAllowedFileTypes;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportUploadGuard
{
    /** @var list<string> */
    private const array EXCEL_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel.sheet.macroenabled.12',
        'application/vnd.ms-excel.sheet.binary.macroenabled.12',
    ];

    /**
     * Returns a validators-domain translation key when the upload must be rejected.
     */
    public function resolveRejectionMessageKey(UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return null;
        }

        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (\in_array($extension, ImportAllowedFileTypes::REJECTED_EXTENSIONS, true)) {
            return 'validation.import.excel_rejected';
        }

        if (!\in_array($extension, ImportAllowedFileTypes::EXTENSIONS, true)) {
            return 'validation.import.file_extensions';
        }

        $detectedMime = strtolower($file->getMimeType() ?? '');

        if ('' !== $detectedMime && \in_array($detectedMime, self::EXCEL_MIME_TYPES, true)) {
            return 'validation.import.excel_rejected';
        }

        $allowedMimes = ImportAllowedFileTypes::EXTENSION_MIME_MAP[$extension];
        if ('' !== $detectedMime && !\in_array($detectedMime, $allowedMimes, true)) {
            return 'validation.import.file_mime_types';
        }

        return null;
    }
}
