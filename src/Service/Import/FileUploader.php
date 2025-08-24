<?php

// src/Service/FileUploader.php
declare(strict_types=1);

namespace App\Service\Import;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploader
{
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')]
        private readonly string $baseDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $absDir = $this->ensureTargetDir($this->baseDir);

        $extension = $file->guessExtension();

        if (null === $extension || '' === $extension) {
            $fallback = $file->getClientOriginalExtension();
            $extension = ('' === $fallback) ? 'bin' : $fallback;
        }

        $fileName = $this->buildFileName($extension);
        $targetAbs = rtrim($absDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;

        $this->moveUploadedFile($file, $absDir, $fileName);

        return $targetAbs;
    }

    private function ensureTargetDir(string $baseDir): string
    {
        $subDir = date('Y').DIRECTORY_SEPARATOR.date('m');
        $absDir = rtrim($baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$subDir;

        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new \RuntimeException(sprintf('Could not create directory for uploads: %s', $absDir));
        }

        return $absDir;
    }

    private function buildFileName(string $extension): string
    {
        return sprintf('import_%s_%s.%s', uniqid('', true), date('Ymd_His'), $extension);
    }

    private function moveUploadedFile(UploadedFile $file, string $absDir, string $fileName): void
    {
        if ($file->isValid()) {
            $fileSize = $file->getSize();
            $fileMime = $file->getClientMimeType();

            try {
                $file->move($absDir, $fileName);
                $this->logger->info('File uploaded successfully', [
                    'target' => $absDir.$fileName,
                    'size' => $fileSize,
                    'mime' => $fileMime,
                ]);

                return;
            } catch (FileException $e) {
                $this->logger->error('File upload failed', [
                    'target' => $absDir.$fileName,
                    'error' => $e->getMessage(),
                    'original' => $file->getClientOriginalName(),
                ]);

                throw $e;
            }
        }
    }
}
