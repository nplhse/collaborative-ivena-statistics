<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

/**
 * One problem in a package, with the path of the field in the file (for example `problem_areas[2].pzc[0]`).
 */
final readonly class CurationViolation
{
    public function __construct(
        public string $path,
        public string $message,
        public ?string $invalidValue = null,
    ) {
    }
}
