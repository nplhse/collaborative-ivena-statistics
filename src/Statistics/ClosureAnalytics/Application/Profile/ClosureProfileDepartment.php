<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileDepartment
{
    public function __construct(
        public int $id,
        public string $name,
        public string $specialityName,
        public bool $fromClosure,
        public bool $fromAssignment,
    ) {
    }
}
