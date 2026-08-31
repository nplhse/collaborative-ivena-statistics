<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use App\User\Domain\Enum\UserActivityType;

final readonly class ProjectActivityFilters
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $untilExclusive = null,
        public ?UserActivityType $type = null,
        public ?string $username = null,
        public ?string $search = null,
    ) {
    }
}
