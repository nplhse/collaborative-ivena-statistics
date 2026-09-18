<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ClosedDepartmentExploreUrlFactory
{
    public const string DATE_QUERY_FORMAT = 'Y-m-d';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    public function listUrl(
        StatisticsFilter $filter,
        StatisticsPeriodBounds $period,
        array $extra = [],
    ): string {
        $params = array_merge(
            ['departmentWasClosed' => 1],
            $this->scopeParams($filter),
            $this->periodParams($period),
            $extra,
        );

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );

        return $this->urlGenerator->generate('app_explore_allocation_list', $params);
    }

    /**
     * @return array<string, scalar>
     */
    private function scopeParams(StatisticsFilter $filter): array
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Hospital => null !== $filter->hospitalId
                ? ['hospitalFilter' => (string) $filter->hospitalId]
                : [],
            StatisticsFilterScope::MyHospitals => ['hospitalFilter' => 'my_hospitals'],
            StatisticsFilterScope::DispatchArea => null !== $filter->dispatchAreaId
                ? ['dispatchArea' => $filter->dispatchAreaId]
                : [],
            StatisticsFilterScope::State => null !== $filter->stateId
                ? ['state' => $filter->stateId]
                : [],
            StatisticsFilterScope::Public,
            StatisticsFilterScope::HospitalCohort => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function periodParams(StatisticsPeriodBounds $period): array
    {
        $params = [];
        if ($period->from instanceof \DateTimeImmutable) {
            $params['createdFrom'] = $period->from->format(self::DATE_QUERY_FORMAT);
        }
        if ($period->toExclusive instanceof \DateTimeImmutable) {
            $params['createdUntil'] = $period->toExclusive->modify('-1 day')->format(self::DATE_QUERY_FORMAT);
        }

        return $params;
    }
}
