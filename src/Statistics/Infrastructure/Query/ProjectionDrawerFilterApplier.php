<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use Doctrine\ORM\QueryBuilder;

final class ProjectionDrawerFilterApplier
{
    public function apply(QueryBuilder $qb, StatisticsDrawerFilter $filter): void
    {
        if (null !== $filter->gender) {
            $qb->andWhere('p.genderCode = :drawerGender')
                ->setParameter('drawerGender', $filter->gender);
        }

        if (null !== $filter->urgency) {
            $qb->andWhere('p.urgencyCode = :drawerUrgency')
                ->setParameter('drawerUrgency', $filter->urgency);
        }

        if (null !== $filter->ageGroup) {
            $this->applyAgeGroup($qb, $filter->ageGroup);
        }

        if (null !== $filter->department) {
            $qb->andWhere('p.departmentId = :drawerDepartmentId')
                ->setParameter('drawerDepartmentId', $filter->department);
        }

        if (null !== $filter->speciality) {
            $qb->andWhere('p.specialityId = :drawerSpecialityId')
                ->setParameter('drawerSpecialityId', $filter->speciality);
        }

        if (null !== $filter->requiresResus) {
            $qb->andWhere('p.requiresResus = :drawerRequiresResus')
                ->setParameter('drawerRequiresResus', $filter->requiresResus);
        }

        if (null !== $filter->requiresCathlab) {
            $qb->andWhere('p.requiresCathlab = :drawerRequiresCathlab')
                ->setParameter('drawerRequiresCathlab', $filter->requiresCathlab);
        }

        if (null !== $filter->isVentilated) {
            $qb->andWhere('p.isVentilated = :drawerIsVentilated')
                ->setParameter('drawerIsVentilated', $filter->isVentilated);
        }

        if (null !== $filter->isShock) {
            $qb->andWhere('p.isShock = :drawerIsShock')
                ->setParameter('drawerIsShock', $filter->isShock);
        }

        if (null !== $filter->isCpr) {
            $qb->andWhere('p.isCpr = :drawerIsCpr')
                ->setParameter('drawerIsCpr', $filter->isCpr);
        }

        if (null !== $filter->isPregnant) {
            $qb->andWhere('p.isPregnant = :drawerIsPregnant')
                ->setParameter('drawerIsPregnant', $filter->isPregnant);
        }

        if (null !== $filter->isWorkAccident) {
            $qb->andWhere('p.isWorkAccident = :drawerIsWorkAccident')
                ->setParameter('drawerIsWorkAccident', $filter->isWorkAccident);
        }

        if (null !== $filter->isInfectious) {
            $qb->andWhere($filter->isInfectious ? 'p.infectionId IS NOT NULL' : 'p.infectionId IS NULL');
        }

        if (null !== $filter->infection) {
            $qb->andWhere('p.infectionId = :drawerInfectionId')
                ->setParameter('drawerInfectionId', $filter->infection);
        }
    }

    private function applyAgeGroup(QueryBuilder $qb, string $ageGroup): void
    {
        $condition = StatisticsAgeGroupFilter::sqlCondition('p.age', $ageGroup);
        if (null !== $condition) {
            $qb->andWhere($condition);
        }
    }
}
