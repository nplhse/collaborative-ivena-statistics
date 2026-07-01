<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

/** @psalm-suppress ClassMustBeFinal */
final readonly class FileChecksumCalculator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private ImportFileStorage $fileStorage,
        private string $algorithm = 'sha256',
    ) {
    }

    /** @return non-empty-string */
    public function forPath(string $path): string
    {
        $abs = $this->fileStorage->resolve($path);

        $hash = @\hash_file($this->algorithm, $abs);

        if (false === $hash) {
            throw new \RuntimeException(sprintf('Failed to compute checksum using "%s" for file: %s', $this->algorithm, $abs));
        }

        return $hash;
    }
}
