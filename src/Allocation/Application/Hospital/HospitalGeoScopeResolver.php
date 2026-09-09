<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;

final readonly class HospitalGeoScopeResolver
{
    public function __construct(
        private HospitalLookupInterface $hospitalLookup,
        private DispatchAreaLookupInterface $dispatchAreaLookup,
        private StateLookupInterface $stateLookup,
    ) {
    }

    public function resolve(HospitalGeoScope $scope): HospitalGeoScopeResolution
    {
        return match ($scope->type) {
            HospitalGeoScopeType::Hospital => $this->resolveHospital($scope),
            HospitalGeoScopeType::DispatchArea => $this->resolveDispatchArea($scope),
            HospitalGeoScopeType::State => $this->resolveState($scope),
        };
    }

    private function resolveHospital(HospitalGeoScope $scope): HospitalGeoScopeResolution
    {
        $hospital = $this->hospitalLookup->findById($scope->id);
        if (!$hospital instanceof Hospital) {
            return HospitalGeoScopeResolution::error(sprintf('Unknown hospital #%d.', $scope->id));
        }

        return HospitalGeoScopeResolution::ok($hospital->getName() ?? sprintf('Hospital #%d', $scope->id), [$hospital]);
    }

    private function resolveDispatchArea(HospitalGeoScope $scope): HospitalGeoScopeResolution
    {
        $dispatchArea = $this->dispatchAreaLookup->findById($scope->id);
        if (!$dispatchArea instanceof DispatchArea) {
            return HospitalGeoScopeResolution::error(sprintf('Unknown dispatch area #%d.', $scope->id));
        }

        $hospitals = $this->hospitalLookup->findByDispatchArea($dispatchArea);

        return HospitalGeoScopeResolution::ok(
            $dispatchArea->getName() ?? sprintf('Dispatch area #%d', $scope->id),
            $this->filterParticipating($hospitals, $scope->participatingOnly),
        );
    }

    private function resolveState(HospitalGeoScope $scope): HospitalGeoScopeResolution
    {
        $state = $this->stateLookup->findById($scope->id);
        if (!$state instanceof State) {
            return HospitalGeoScopeResolution::error(sprintf('Unknown federal state #%d.', $scope->id));
        }

        $hospitals = $this->hospitalLookup->findByState($state);

        return HospitalGeoScopeResolution::ok(
            $state->getName() ?? sprintf('State #%d', $scope->id),
            $this->filterParticipating($hospitals, $scope->participatingOnly),
        );
    }

    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<Hospital>
     */
    private function filterParticipating(array $hospitals, bool $participatingOnly): array
    {
        if (!$participatingOnly) {
            return $hospitals;
        }

        return array_values(array_filter(
            $hospitals,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
    }
}
