<?php

declare(strict_types=1);

namespace App\Allocation\Application\Event;

/**
 * An administrator approved or rejected a hospital assignment request (#975); dispatched after the commit.
 * `previousOwnerId` is set when the approval transferred the ownership of the hospital.
 */
final readonly class HospitalAssignmentRequestDecided
{
    public function __construct(
        public int $requestId,
        public ?int $previousOwnerId,
    ) {
    }
}
