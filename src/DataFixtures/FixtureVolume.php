<?php

declare(strict_types=1);

namespace App\DataFixtures;

final readonly class FixtureVolume
{
    public function __construct(
        public int $hospitalsActive,
        public int $imports,
        public int $allocations,
        public int $mciCases,
        public string $period,
        public string $pattern,
        public bool $rebuildProjection,
    ) {
    }
}
