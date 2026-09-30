<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Allocation\Domain\Enum\AllocationUrgency;

final readonly class ClosureOverlappingAllocationRow
{
    public function __construct(
        public string $publicId,
        public \DateTimeImmutable $createdAt,
        public int $departmentId,
        public string $departmentName,
        public ?string $indicationName,
        public AllocationUrgency $urgency,
    ) {
    }
}
