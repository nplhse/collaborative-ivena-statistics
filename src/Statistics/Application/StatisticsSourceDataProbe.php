<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Import\Application\Service\ImportUploadTargets;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use App\User\Domain\Entity\User;

final readonly class StatisticsSourceDataProbe
{
    public function __construct(
        private ProjectionTimeSeriesQuery $timeSeriesQuery,
        private StatisticsScopeResolver $scopeResolver,
        private StatisticsContextFactory $contextFactory,
        private ImportUploadTargets $uploadTargets,
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
        // The empty states offer an allocation import (#1351).
        return $user instanceof User && $this->uploadTargets->canUpload($user, ImportType::ALLOCATION);
    }
}
