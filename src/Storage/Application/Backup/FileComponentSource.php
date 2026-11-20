<?php

declare(strict_types=1);

namespace App\Storage\Application\Backup;

/**
 * Where a file component is read from, and which top-level entries below it are left out.
 */
final readonly class FileComponentSource
{
    /**
     * @param list<string> $excluded names directly below $path that are not part of the component
     */
    public function __construct(
        public BackupComponent $component,
        public string $path,
        public array $excluded = [],
    ) {
    }
}
