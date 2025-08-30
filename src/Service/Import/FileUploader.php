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

final class FileUploader
{
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')] private readonly string $baseDir,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function upload(UploadedFile $file): string
    {
        $absDir = Path::join($this->baseDir, date('Y'), date('m'));
        $this->filesystem->mkdir($absDir, 0775);

        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';

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
}
