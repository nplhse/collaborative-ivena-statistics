<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Package;

use Cois\Plugin\EdVisit\Application\Curation\Package\Validation\ValidCurationPackage;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The curated MTS catalog and plausibility problem areas of one instance (#1198, ADR 032, ADR 033).
 *
 * Field rules run in the default group; package rules (unique codes, references, counts, checksum) run in the group
 * "package" once the fields are valid.
 */
#[ValidCurationPackage(groups: [self::PACKAGE_RULES])]
final readonly class CurationPackage
{
    public const string PACKAGE_RULES = 'package';

    /**
     * @param list<CatalogEntryData> $diagrams
     * @param list<CatalogEntryData> $indicators
     * @param list<AliasData>        $aliases
     * @param list<AssignmentData>   $assignments
     * @param list<ProblemAreaData>  $problemAreas
     */
    public function __construct(
        #[Assert\Valid]
        public PackageMeta $meta,
        #[Assert\Valid]
        public array $diagrams = [],
        #[Assert\Valid]
        public array $indicators = [],
        #[Assert\Valid]
        public array $aliases = [],
        #[Assert\Valid]
        public array $assignments = [],
        #[SerializedName('problem_areas')]
        #[Assert\Valid]
        public array $problemAreas = [],
    ) {
    }

    /**
     * Entries per section, keyed like the file.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'diagrams' => \count($this->diagrams),
            'indicators' => \count($this->indicators),
            'aliases' => \count($this->aliases),
            'assignments' => \count($this->assignments),
            'problem_areas' => \count($this->problemAreas),
        ];
    }

    public function withMeta(PackageMeta $meta): self
    {
        return new self($meta, $this->diagrams, $this->indicators, $this->aliases, $this->assignments, $this->problemAreas);
    }
}
