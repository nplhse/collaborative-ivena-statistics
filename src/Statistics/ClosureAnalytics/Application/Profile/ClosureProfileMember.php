<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileMember
{
    public function __construct(
        public int $specialityId,
        public string $specialityName,
        public int $departmentId,
        public string $departmentName,
        public string $careLevel,
        public string $reason,
    ) {
    }
}
