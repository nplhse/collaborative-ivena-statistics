<?php

// src/Service/FileUploader.php
declare(strict_types=1);

namespace App\Service\Import;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** @psalm-suppress ClassMustBeFinal */
readonly class FileUploader
{
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')] private string $baseDir,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $absDir = Path::join($this->baseDir, date('Y'), date('m'));
        $this->filesystem->mkdir($absDir, 0775);

        $ext = $this->resolveExtension($file);
        $fileName = sprintf('import_%s_%s.%s', uniqid('', true), date('Ymd_His'), $ext);
        $targetAbs = Path::canonicalize(Path::join($absDir, $fileName));

        try {
            $size = $file->getSize();
            $mime = $file->getClientMimeType();
            $orig = $file->getClientOriginalName();

            $file->move($absDir, $fileName);

            $relative = Path::makeRelative($targetAbs, $this->projectDir);
            $relative = str_replace('\\', '/', $relative);

            $this->logger->info('upload.success', [
                'target_abs' => $targetAbs,
                'target_rel' => $relative,
                'size' => $size,
                'mime' => $mime,
                'orig' => $orig,
            ]);

            return $relative;
        } catch (FileException $e) {
            $this->logger->error('upload.failed', [
                'target_abs' => $targetAbs,
                'error' => $e->getMessage(),
                'orig' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $originalExt = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ('' !== $originalExt) {
            return $originalExt;
        }

        $mime = strtolower($file->getClientMimeType());
        $csvMimes = [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
        ];

        if (in_array($mime, $csvMimes, true)) {
            return 'csv';
        }

        $guessed = $file->guessExtension();
        if (is_string($guessed) && '' !== $guessed) {
            return strtolower($guessed);
        }

        return 'bin';
    }
}
