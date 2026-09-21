<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Import\Application\Service\ImportListAccess;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Entity\User;

final readonly class StatisticsSourceDataProbe
{
    public function __construct(
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
        private StatisticsContextFactory $contextFactory,
        private ImportListAccess $importListAccess,
    ) {
    }

    public function hasSourceData(?User $user, StatisticsFilter $filter): bool
    {
        $scope = $this->scopeResolver->resolveCriteria(
            $this->contextFactory->create($user, $filter),
        );

        return $this->timeSeriesQuery->hasAnyInScope($scope->hospitalIds, $scope->dispatchAreaId);
    }

    public function canImport(?User $user): bool
    {
        return $user instanceof User && [] !== $this->importListAccess->resolveAccessibleHospitalIds($user);
    }
}
