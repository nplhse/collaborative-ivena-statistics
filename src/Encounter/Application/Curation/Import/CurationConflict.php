<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Import;

/**
 * A package row that differs from the target. The import never changes the target, so a conflict stops the whole
 * import; it is resolved through the curation in the back office and a new run.
 */
final readonly class CurationConflict
{
    public function __construct(
        public string $path,
        public string $message,
        public string $package,
        public string $target,
        public ?string $resolution = null,
    ) {
    }
}
