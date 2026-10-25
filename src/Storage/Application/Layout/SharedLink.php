<?php

declare(strict_types=1);

namespace App\Storage\Application\Layout;

/**
 * A symlink in the current release that points into shared/, e.g. var/imports -> shared/var/imports.
 * Both paths are relative and use "/" as separator.
 */
final readonly class SharedLink
{
    public function __construct(
        public string $releasePath,
        public string $sharedPath,
    ) {
    }
}
