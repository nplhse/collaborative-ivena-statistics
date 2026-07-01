<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class ImportFileStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private Filesystem $filesystem,
        private LoggerInterface $importLogger,
    ) {
    }

    public function resolve(string $storedPath): string
    {
        if ($this->isAbsolutePath($storedPath)) {
            return $storedPath;
        }

        return Path::join($this->projectDir, $storedPath);
    }

    public function toRelative(string $absolutePath): string
    {
        $relative = Path::makeRelative($absolutePath, $this->projectDir);

        return str_replace('\\', '/', $relative);
    }

    public function delete(?string $storedPath, string $logEvent, int $importId): void
    {
        if (null === $storedPath || '' === $storedPath) {
            return;
        }

        $absPath = $this->resolve($storedPath);
        if (!$this->filesystem->exists($absPath)) {
            return;
        }

        try {
            $this->filesystem->remove($absPath);
        } catch (IOException $e) {
            $this->importLogger->warning('import.file.delete_failed', [
                'import_id' => $importId,
                'path' => $absPath,
                'event' => $logEvent,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->importLogger->info($logEvent, [
            'import_id' => $importId,
            'path' => $absPath,
        ]);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === $path[0]) {
            return true;
        }

        return (bool) \preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
