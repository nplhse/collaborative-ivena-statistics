<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A curated problem area of the plausibility table with its PZC codes and diagram rules (ADR 033). No cells, counts
 * or snapshots: the target derives its seed draft from the areas.
 */
final readonly class ProblemAreaData
{
    /**
     * @param list<int>          $pzc      ascending
     * @param list<AreaRuleData> $diagrams ordered by diagram code
     */
    public function __construct(
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected an area code (a–z, 0–9, _; at most 64 characters).')]
        public string $code,
        #[SerializedName('german_name')]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $germanName,
        #[SerializedName('english_name')]
        #[Assert\Length(min: 1, max: 255)]
        public ?string $englishName,
        #[Assert\Unique(message: 'A PZC is listed twice.')]
        #[Assert\All([new Assert\Range(min: 0, max: 999)])]
        public array $pzc = [],
        #[Assert\Valid]
        public array $diagrams = [],
    ) {
    }
}
