<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\ImportAllowedFileTypes;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** @psalm-suppress ClassMustBeFinal */
final readonly class FileUploader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(param: 'app.imports_base_dir')] private string $baseDir,
        private ImportFileStorage $fileStorage,
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

            $relative = $this->fileStorage->toRelative($targetAbs);

            $this->logger->info('upload.success', [
                'target_rel' => $relative,
                'size' => $size,
                'mime' => $mime,
                'orig' => $orig,
            ]);

            return $relative;
        } catch (FileException $e) {
            $this->logger->error('upload.failed', [
                'target_rel' => $this->fileStorage->toRelative($targetAbs),
                'error' => $e->getMessage(),
                'orig' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $originalExt = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (\in_array($originalExt, ImportAllowedFileTypes::REJECTED_EXTENSIONS, true)) {
            throw new FileException('Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.');
        }

        if ('' !== $originalExt && \in_array($originalExt, ImportAllowedFileTypes::EXTENSIONS, true)) {
            return $originalExt;
        }

        $mime = strtolower($file->getClientMimeType());
        $allowedMimes = [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
        ];

        if (\in_array($mime, $allowedMimes, true)) {
            return 'csv';
        }

        $guessed = $file->guessExtension();
        if (is_string($guessed) && '' !== $guessed) {
            $guessed = strtolower($guessed);
            if (\in_array($guessed, ImportAllowedFileTypes::REJECTED_EXTENSIONS, true)) {
                throw new FileException('Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.');
            }
            if (\in_array($guessed, ImportAllowedFileTypes::EXTENSIONS, true)) {
                return $guessed;
            }
        }

        throw new FileException(sprintf('Unsupported import file type. Allowed extensions: %s.', implode(', ', ImportAllowedFileTypes::EXTENSIONS)));
    }
}
