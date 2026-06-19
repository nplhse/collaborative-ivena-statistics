<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;

final readonly class GenericAnalysisDimensionPolicy
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
    ) {
    }

    public function isAllowed(string $dimensionKey, StatisticsFilter $filter, ?User $user): bool
    {
        return match ($dimensionKey) {
            'hospital' => $this->allowsHospital($filter, $user),
            'state' => $this->allowsState($filter, $user),
            'dispatchArea' => $this->allowsDispatchArea($filter, $user),
            'hospital_cohort' => true,
            default => false,
        };
    }

    private function isAdmin(?User $user): bool
    {
        return $user instanceof User && $this->hospitalAccess->isAdminHospitalScopeUser($user);
    }

    private function allowsHospital(StatisticsFilter $filter, ?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return match ($filter->scope) {
            StatisticsFilterScope::MyHospitals,
            StatisticsFilterScope::Hospital,
            StatisticsFilterScope::State,
            StatisticsFilterScope::DispatchArea,
            StatisticsFilterScope::HospitalCohort => true,
            default => false,
        };
    }

    private function allowsState(StatisticsFilter $filter, ?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return StatisticsFilterScope::State === $filter->scope;
    }

    private function allowsDispatchArea(StatisticsFilter $filter, ?User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return StatisticsFilterScope::DispatchArea === $filter->scope;
    }
}
