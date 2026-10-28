<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;

/**
 * One closure interval of one department, before it is written.
 */
final readonly class PlannedClosure
{
    public function __construct(
        public string $department,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public ClosureCareLevel $careLevel,
        public ClosureReason $reason,
        public ?string $sourceGroupId,
    ) {
    }
}
