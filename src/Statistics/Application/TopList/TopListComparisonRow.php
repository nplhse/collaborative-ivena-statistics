<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListComparisonRow
{
    public function __construct(
        public string $identity,
        public string $label,
        public ?int $rankA,
        public ?int $countA,
        public ?float $shareA,
        public ?int $rankB,
        public ?int $countB,
        public ?float $shareB,
        public ?int $deltaCount,
        public ?float $deltaShare,
        public ?int $rankMovement,
        public bool $onlyInA,
        public bool $onlyInB,
        public ?int $entityId = null,
    ) {
    }
}
