<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

use App\Statistics\HospitalPopulation\Application\DTO\HospitalPopulationSnapshot;

final readonly class HospitalPopulationSnapshotEnricher
{
    /**
     * @param list<HospitalPopulationSnapshot> $snapshots
     * @param list<int>                        $hospitalIdsWithAllocations
     * @param array<int, int>                  $allocationCountsByHospitalId
     *
     * @return list<HospitalPopulationSnapshot>
     */
    public function enrich(
        array $snapshots,
        array $hospitalIdsWithAllocations,
        array $allocationCountsByHospitalId,
    ): array {
        $withDataLookup = array_fill_keys($hospitalIdsWithAllocations, true);

        return array_map(
            static function (HospitalPopulationSnapshot $snapshot) use ($withDataLookup, $allocationCountsByHospitalId): HospitalPopulationSnapshot {
                $hasAllocations = isset($withDataLookup[$snapshot->id]);
                $allocationCount = $allocationCountsByHospitalId[$snapshot->id] ?? 0;

                return new HospitalPopulationSnapshot(
                    id: $snapshot->id,
                    name: $snapshot->name,
                    stateId: $snapshot->stateId,
                    stateName: $snapshot->stateName,
                    dispatchAreaId: $snapshot->dispatchAreaId,
                    dispatchAreaName: $snapshot->dispatchAreaName,
                    latitude: $snapshot->latitude,
                    longitude: $snapshot->longitude,
                    beds: $snapshot->beds,
                    size: $snapshot->size,
                    careLevel: $snapshot->careLevel,
                    urbanity: $snapshot->urbanity,
                    hasAllocations: $hasAllocations,
                    isParticipating: $snapshot->isParticipating,
                    allocationCount: $allocationCount,
                );
            },
            $snapshots,
        );
    }
}
