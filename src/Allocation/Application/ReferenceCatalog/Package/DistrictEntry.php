<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A district and its dispatch area. Boundaries are not part of a package (ADR 041).
 */
final readonly class DistrictEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\Regex(pattern: '/^\d{5}$/D', message: 'A district key (AGS) has five digits.')]
        public string $ags,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Length(max: 64)]
        public ?string $kind,
        /** Official two-digit state key, the start of the AGS. */
        #[Assert\Regex(pattern: '/^\d{2}$/D', message: 'A state key has two digits.')]
        public string $state,
        /** Public id of the dispatch area, null while the district is not assigned. */
        #[SerializedName('dispatch_area')]
        #[Assert\Uuid]
        public ?string $dispatchArea = null,
    ) {
    }
}
