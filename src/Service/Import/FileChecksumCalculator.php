<?php

namespace App\Service\Import;

final readonly class FileChecksumCalculator
{
    public function __construct(private string $algo = 'sha256')
    {
    }

    /** @return non-empty-string */
    public function forPath(string $absolutePath): string
    {
        if (!\is_file($absolutePath)) {
            throw new \RuntimeException("File not found: $absolutePath");
        }

        $hex = \hash_file($this->algo, $absolutePath);

        if (false === $hex) {
            throw new \RuntimeException("Checksum failed for: $absolutePath");
        }

        /* @var non-empty-string */
        return $hex;
    }
}
