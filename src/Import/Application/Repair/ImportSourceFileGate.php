<?php

declare(strict_types=1);

namespace App\Import\Application\Repair;

use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Domain\Enum\ImportSourceFileStatus;

final readonly class ImportSourceFileGate
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private ImportFileStorage $fileStorage,
    ) {
    }

    public function inspect(?string $storedPath): ImportSourceFileStatus
    {
        if (null === $storedPath || '' === trim($storedPath)) {
            return ImportSourceFileStatus::EmptyPath;
        }

        try {
            $absolute = $this->fileStorage->resolve($storedPath);
        } catch (ImportFilePathOutsideBaseException) {
            return ImportSourceFileStatus::OutsideBase;
        }

        if (!\is_file($absolute)) {
            return ImportSourceFileStatus::NotFound;
        }

        if (!\is_readable($absolute)) {
            return ImportSourceFileStatus::Unreadable;
        }

        $size = filesize($absolute);
        if (false === $size || $size <= 0) {
            return ImportSourceFileStatus::EmptyFile;
        }

        return ImportSourceFileStatus::Ready;
    }
}
