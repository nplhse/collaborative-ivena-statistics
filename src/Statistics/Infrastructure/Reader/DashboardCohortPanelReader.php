<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Reader;

use App\Statistics\Domain\Model\Scope;

/** @psalm-suppress UnusedClass */
final readonly class DashboardCohortPanelReader
{
    public function __construct(
        private DashboardCohortSumsReader $sums,
        private DashboardCohortStatsReader $stats,
    ) {
    }

    /**
     * @return array{
     *   sums: \App\Statistics\Domain\Model\CohortSumsView|null,
     *   stats: \App\Statistics\Domain\Model\CohortStatsView|null
     * }
     */
    public function read(Scope $scope): array
    {
        return [
            'sums' => $this->sums->read($scope),
            'stats' => $this->stats->read($scope),
        ];
    }

    public static function isCohortScope(string $t): bool
    {
        return \in_array($t, ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'], true);
    }
}
