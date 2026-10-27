<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\Package\Validation\ValidReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A portable reference catalog package (ADR 020).
 *
 * Field rules run in the default group; package rules (unique ids and keys,
 * references, counts, checksum) run in the group "package" once the fields are valid.
 */
#[ValidReferenceCatalogPackage(groups: [self::PACKAGE_RULES])]
final readonly class ReferenceCatalogPackage
{
    public const string PACKAGE_RULES = 'package';

    /**
     * @param list<StateEntry>                $states
     * @param list<DispatchAreaEntry>         $dispatchAreas
     * @param list<AliasedEntry>              $departments
     * @param list<AliasedEntry>              $specialities
     * @param list<NamedEntry>                $assignments
     * @param list<NamedEntry>                $occasions
     * @param list<NamedEntry>                $infections
     * @param list<NamedEntry>                $secondaryTransports
     * @param list<NormalizedIndicationEntry> $indicationsNormalized
     * @param list<RawIndicationEntry>        $indicationsRaw
     * @param list<IndicationGroupEntry>      $indicationGroups
     * @param list<HospitalEntry>             $hospitals
     */
    public function __construct(
        #[Assert\Valid]
        public PackageMeta $meta,
        #[Assert\Valid]
        public array $states = [],
        #[SerializedName('dispatch_areas')]
        #[Assert\Valid]
        public array $dispatchAreas = [],
        #[Assert\Valid]
        public array $departments = [],
        #[Assert\Valid]
        public array $specialities = [],
        #[Assert\Valid]
        public array $assignments = [],
        #[Assert\Valid]
        public array $occasions = [],
        #[Assert\Valid]
        public array $infections = [],
        #[SerializedName('secondary_transports')]
        #[Assert\Valid]
        public array $secondaryTransports = [],
        #[SerializedName('indications_normalized')]
        #[Assert\Valid]
        public array $indicationsNormalized = [],
        #[SerializedName('indications_raw')]
        #[Assert\Valid]
        public array $indicationsRaw = [],
        #[SerializedName('indication_groups')]
        #[Assert\Valid]
        public array $indicationGroups = [],
        #[Assert\Valid]
        public array $hospitals = [],
    ) {
    }

    /**
     * Number of entries per catalog type, keyed by the type's CLI name.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            $counts[$type->value] = \count($this->entriesOf($type));
        }

        return $counts;
    }

    /**
     * @return list<CatalogEntry>
     */
    public function entriesOf(ReferenceCatalogType $type): array
    {
        return match ($type) {
            ReferenceCatalogType::State => $this->states,
            ReferenceCatalogType::DispatchArea => $this->dispatchAreas,
            ReferenceCatalogType::Department => $this->departments,
            ReferenceCatalogType::Speciality => $this->specialities,
            ReferenceCatalogType::Assignment => $this->assignments,
            ReferenceCatalogType::Occasion => $this->occasions,
            ReferenceCatalogType::Infection => $this->infections,
            ReferenceCatalogType::SecondaryTransport => $this->secondaryTransports,
            ReferenceCatalogType::IndicationNormalized => $this->indicationsNormalized,
            ReferenceCatalogType::IndicationRaw => $this->indicationsRaw,
            ReferenceCatalogType::IndicationGroup => $this->indicationGroups,
            ReferenceCatalogType::Hospital => $this->hospitals,
        };
    }
}
