<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileSpecialityLink
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
