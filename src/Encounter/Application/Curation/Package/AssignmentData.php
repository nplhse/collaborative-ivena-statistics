<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A diagram–indicator assignment between two curated entries, by code (ADR 032).
 */
final readonly class AssignmentData
{
    public function __construct(
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $diagram,
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $indicator,
    ) {
    }
}
