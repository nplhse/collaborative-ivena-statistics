<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * The names and PZC codes of a reference catalog that a pattern package may use.
 */
final readonly class PatternCatalogNames
{
    /**
     * @param list<string> $indicationCodes     three-digit PZC codes
     * @param list<string> $departments
     * @param list<string> $occasions
     * @param list<string> $assignments
     * @param list<string> $infections
     * @param list<string> $secondaryTransports
     */
    public function __construct(
        public array $indicationCodes,
        public array $departments,
        public array $occasions,
        public array $assignments,
        public array $infections,
        public array $secondaryTransports,
    ) {
    }
}
