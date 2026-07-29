<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;

final readonly class CatalogMappedRawTerm
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $code,
        public string $name,
        public IndicationRawReviewStatus $reviewStatus,
        public int $occurrenceCount,
        public ?\DateTimeImmutable $reviewedAt = null,
    ) {
    }
}
