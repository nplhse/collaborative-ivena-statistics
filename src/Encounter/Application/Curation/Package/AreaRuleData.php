<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use App\Encounter\Domain\Entity\PlausibilityCell;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A curated rule of a problem area: an MTS diagram plausible with the area's PZC codes, with points (ADR 033).
 */
final readonly class AreaRuleData
{
    public function __construct(
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $code,
        #[Assert\Range(min: PlausibilityCell::MIN_POINTS, max: PlausibilityCell::MAX_POINTS)]
        public int $points,
    ) {
    }
}
