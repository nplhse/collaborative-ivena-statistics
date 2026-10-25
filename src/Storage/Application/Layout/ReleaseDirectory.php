<?php

declare(strict_types=1);

namespace App\Storage\Application\Layout;

/**
 * A Deployer release directory, releases/<name>.
 */
final readonly class ReleaseDirectory
{
    public function __construct(
        public string $name,
        public string $path,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}
