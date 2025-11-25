<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Model;

/**
 * @psalm-type RatesMap = array<string, CohortRate>
 *
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress ClassMustBeFinal
 */
class CohortStatsView
{
    /**
     * @param array<string, CohortRate> $rates
     */
    public function __construct(
        public Scope $scope,
        public int $n,
        public float $meanTotal,
        public \DateTimeImmutable $computedAt,
        public array $rates,
    ) {
    }
}
