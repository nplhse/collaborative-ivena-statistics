<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

use App\Allocation\Domain\Enum\AllocationUrgency;

final readonly class ClosureEventAllocationRow
{
    public function __construct(
        public string $publicId,
        public \DateTimeImmutable $createdAt,
        public string $specialityName,
        public string $departmentName,
        public AllocationUrgency $urgency,
        public bool $departmentWasClosed,
        public bool $isEventMemberDepartment,
        public bool $closedAtAssignmentTime,
        public ClosureEventAssignmentRowContext $context,
    ) {
    }
}
