<?php

declare(strict_types=1);

namespace App\Allocation\Application\Event;

/**
 * A participant submitted a hospital assignment request (#975); dispatched after the commit.
 */
final readonly class HospitalAssignmentRequested
{
    public function __construct(
        public int $requestId,
        public string $requestPublicId,
    ) {
    }
}
