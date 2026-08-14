<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;

/**
 * DBAL WHERE fragments for {@see StatisticsDrawerFilter} on allocation_stats_projection.
 */
final class ProjectionDrawerFilterSql
{
    /**
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public function apply(StatisticsDrawerFilter $filter, string $columnPrefix = ''): array
    {
        $prefix = '' === $columnPrefix ? '' : rtrim($columnPrefix, '.').'.';
        $conditions = [];
        $params = [];

        if (null !== $filter->gender) {
            $conditions[] = sprintf('%sgender_code = :drawer_gender', $prefix);
            $params['drawer_gender'] = $filter->gender;
        }

        if (null !== $filter->urgency) {
            $conditions[] = sprintf('%surgency_code = :drawer_urgency', $prefix);
            $params['drawer_urgency'] = $filter->urgency;
        }

        if (null !== $filter->ageGroup) {
            $ageCondition = StatisticsAgeGroupFilter::sqlCondition($prefix.'age', $filter->ageGroup);
            if (null !== $ageCondition) {
                $conditions[] = $ageCondition;
            }
        }

        if (null !== $filter->department) {
            $conditions[] = sprintf('%sdepartment_id = :drawer_department_id', $prefix);
            $params['drawer_department_id'] = $filter->department;
        }

        if (null !== $filter->speciality) {
            $conditions[] = sprintf('%sspeciality_id = :drawer_speciality_id', $prefix);
            $params['drawer_speciality_id'] = $filter->speciality;
        }

        if (null !== $filter->requiresResus) {
            $conditions[] = sprintf('%srequires_resus = :drawer_requires_resus', $prefix);
            $params['drawer_requires_resus'] = $filter->requiresResus;
        }

        if (null !== $filter->requiresCathlab) {
            $conditions[] = sprintf('%srequires_cathlab = :drawer_requires_cathlab', $prefix);
            $params['drawer_requires_cathlab'] = $filter->requiresCathlab;
        }

        if (null !== $filter->isVentilated) {
            $conditions[] = sprintf('%sis_ventilated = :drawer_is_ventilated', $prefix);
            $params['drawer_is_ventilated'] = $filter->isVentilated;
        }

        if (null !== $filter->isShock) {
            $conditions[] = sprintf('%sis_shock = :drawer_is_shock', $prefix);
            $params['drawer_is_shock'] = $filter->isShock;
        }

        if (null !== $filter->isCpr) {
            $conditions[] = sprintf('%sis_cpr = :drawer_is_cpr', $prefix);
            $params['drawer_is_cpr'] = $filter->isCpr;
        }

        if (null !== $filter->isPregnant) {
            $conditions[] = sprintf('%sis_pregnant = :drawer_is_pregnant', $prefix);
            $params['drawer_is_pregnant'] = $filter->isPregnant;
        }

        if (null !== $filter->isWorkAccident) {
            $conditions[] = sprintf('%sis_work_accident = :drawer_is_work_accident', $prefix);
            $params['drawer_is_work_accident'] = $filter->isWorkAccident;
        }

        if (null !== $filter->isInfectious) {
            $conditions[] = $filter->isInfectious
                ? sprintf('%sinfection_id IS NOT NULL', $prefix)
                : sprintf('%sinfection_id IS NULL', $prefix);
        }

        if (null !== $filter->infection) {
            $conditions[] = sprintf('%sinfection_id = :drawer_infection_id', $prefix);
            $params['drawer_infection_id'] = $filter->infection;
        }

        return [$conditions, $params];
    }
}
