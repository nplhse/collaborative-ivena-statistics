<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
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
        #[Autowire('%app.imports_base_dir%')]
        private string $importsBaseDir,
        private Filesystem $filesystem,
        private LoggerInterface $importLogger,
    ) {
    }

    public function resolve(string $storedPath): string
    {
        $normalized = str_replace('\\', '/', $storedPath);

        if ('' === $normalized || str_contains($normalized, "\0")) {
            throw new ImportFilePathOutsideBaseException($storedPath);
        }

        if ($this->isAbsolutePath($normalized)) {
            $candidate = Path::canonicalize($normalized);
        } else {
            $candidate = Path::canonicalize(Path::join($this->toRealPath($this->projectDir), $normalized));
        }

        $candidate = $this->toRealPath($candidate);
        $base = $this->toRealPath($this->importsBaseDir);
        $this->assertPathIsUnderBase($candidate, $base);

        return $candidate;
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

        try {
            $absPath = $this->resolve($storedPath);
        } catch (ImportFilePathOutsideBaseException) {
            return;
        }

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

    /**
     * Resolve symlinks for existing path prefixes (e.g. /var → /private/var on macOS).
     */
    private function toRealPath(string $path): string
    {
        $canonical = Path::canonicalize($path);
        $real = realpath($canonical);
        if (false !== $real) {
            return $real;
        }

        $parts = [];
        $current = $canonical;
        while (!\is_dir($current) && !\is_file($current)) {
            $parent = \dirname($current);
            if ($parent === $current) {
                return $canonical;
            }
            array_unshift($parts, \basename($current));
            $current = $parent;
        }

        $realParent = realpath($current);
        if (false === $realParent) {
            return $canonical;
        }

        return [] === $parts
            ? $realParent
            : Path::canonicalize(Path::join($realParent, ...$parts));
    }

    private function assertPathIsUnderBase(string $path, string $base): void
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedBase = rtrim(str_replace('\\', '/', $base), '/');

        if ($normalizedPath === $normalizedBase) {
            return;
        }

        if (!str_starts_with($normalizedPath, $normalizedBase.'/')) {
            throw new ImportFilePathOutsideBaseException($path);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        if ('/' === $path[0] || DIRECTORY_SEPARATOR === $path[0]) {
            return true;
        }

        return (bool) \preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
