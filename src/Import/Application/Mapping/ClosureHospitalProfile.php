<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

/**
 * Hospital labels seen in one closure file, compared with the catalog.
 *
 * The selected hospital stays the assignment target. This profile only decides
 * whether a row's short name is a harmless label or a real contradiction.
 *
 * With the development-only hospital override (#878) a single file label that names another
 * catalog hospital is accepted; `overriddenFileLabel` holds that label. Files with several
 * hospital labels are still checked row by row.
 */
final readonly class ClosureHospitalProfile
{
    /**
     * @param list<string>             $distinctDisplays            first-seen file labels
     * @param list<string>             $normalizedShortNames        parallel to distinctDisplays
     * @param array<string, list<int>> $hospitalIdsByNormalizedName
     * @param array<string, string>    $catalogDisplayByNormalized
     */
    public function __construct(
        public int $selectedHospitalId,
        public string $selectedDisplayName,
        public string $selectedNormalizedName,
        public bool $multiple,
        public array $distinctDisplays,
        public array $normalizedShortNames,
        public array $hospitalIdsByNormalizedName,
        public array $catalogDisplayByNormalized,
        public ?string $differingFileLabel,
        public bool $hospitalOverride = false,
        public ?string $overriddenFileLabel = null,
    ) {
    }
}
