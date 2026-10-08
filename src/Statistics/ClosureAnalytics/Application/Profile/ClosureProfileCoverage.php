<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileCoverage
{
    /**
     * @param list<list<array{year: int, count: int, intensity: float, future: bool, hasData: bool}>> $yearHeatmap
     */
    public function __construct(
        public ?\DateTimeImmutable $firstAt,
        public ?\DateTimeImmutable $lastAt,
        public array $yearHeatmap,
    ) {
    }

    public function hasData(): bool
    {
        return [] !== $this->yearHeatmap;
    }

    public static function empty(): self
    {
        return new self(null, null, []);
    }
}
