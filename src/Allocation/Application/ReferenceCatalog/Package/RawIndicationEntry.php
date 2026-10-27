<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Raw indication label as found in source files, with its curated mapping.
 *
 * Only the curated target is carried; the import sets `normalized` to it (ADR 020).
 */
final readonly class RawIndicationEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\Regex(pattern: '/^\d{3}$/', message: 'Expected a three-digit code.')]
        public string $code,
        /** May be empty: some source rows carry only a code. */
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Valid]
        public RawIndicationReview $review,
        /** Public id of the normalized indication, null for an unmapped label. */
        #[Assert\Uuid]
        public ?string $target = null,
        #[SerializedName('first_matched_at')]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        public ?string $firstMatchedAt = null,
    ) {
    }
}
