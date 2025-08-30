<?php

namespace App\Service\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

final readonly class FileChecksumCalculator
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private string $algorithm = 'sha256',
    ) {
    }

    /** @return non-empty-string */
    public function forPath(string $path): string
    {
        $abs = $this->resolvePath($path);

        $hash = @\hash_file($this->algorithm, $abs);

        if (false === $hash) {
            throw new \RuntimeException(sprintf('Failed to compute checksum using "%s" for file: %s', $this->algorithm, $abs));
        }

        return $hash;
    }

    private function resolvePath(string $stored): string
    {
        if ($this->isAbsolutePath($stored)) {
            return $stored;
        }

        return Path::join($this->projectDir, $stored);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        // Unix: starts with /
        if (DIRECTORY_SEPARATOR === $path[0]) {
            return true;
        }

        // Windows: drive letter + colon
        if (\preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return true;
        }

        return false;
    }
}
