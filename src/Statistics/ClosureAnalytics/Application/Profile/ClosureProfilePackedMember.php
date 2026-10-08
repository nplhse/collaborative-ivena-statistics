<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfilePackedMember
{
    public function __construct(
        public string $specialityName,
        public string $departmentName,
        public string $careLevel,
        public string $reason,
    ) {
    }
}
