<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * One problem in a pattern package, with the path of the table and key in the file
 * (for example `indication_groups.332.departments.Kardiologie`).
 */
final readonly class PatternPackageViolation
{
    public function __construct(
        public string $path,
        public string $message,
        public ?string $invalidValue = null,
    ) {
    }
}
