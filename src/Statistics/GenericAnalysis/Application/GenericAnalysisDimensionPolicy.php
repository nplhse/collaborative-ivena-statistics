<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\User\Domain\Entity\User;

final readonly class GenericAnalysisDimensionPolicy
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
        private DimensionRegistry $dimensionRegistry,
    ) {
    }

    public function isAllowed(string $dimensionKey, StatisticsFilter $filter, ?User $user): bool
    {
        return match ($dimensionKey) {
            'hospital' => $this->allowsHospital($user),
            'state' => $this->allowsState($filter, $user),
            'dispatchArea' => $this->allowsDispatchArea($filter, $user),
            'hospital_cohort' => true,
            default => $this->dimensionRegistry->has($dimensionKey),
        };
    }

    private function isAdmin(?User $user): bool
    {
        return $user instanceof User && $this->hospitalAccess->isAdminHospitalScopeUser($user);
    }

    /**
     * A hospital breakdown names each hospital with its figures, so only admins get it for now.
     * Comparing the own hospital with others is planned as its own feature.
     */
    private function allowsHospital(?User $user): bool
    {
        return $this->isAdmin($user);
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
