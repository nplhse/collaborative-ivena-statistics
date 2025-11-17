<?php

namespace App\Service\Statistics;

use App\Model\HospitalCompositionStats;
use App\Model\HospitalGroupStats;
use App\Query\HospitalCompositionQuery;

final class HospitalCompositionService
{
    public function __construct(
        private HospitalCompositionQuery $query,
    ) {}

    public function compute(): HospitalCompositionStats
    {
        $totalHospitals               = $this->query->countHospitals();
        $totalParticipantHospitals    = $this->query->countParticipantHospitals();
        $totalAllocations             = $this->query->countAllocations();
        $totalParticipantAllocations  = $this->query->countParticipantAllocations();

        $byTierRows      = $this->query->aggregateByTier();
        $byLocationRows  = $this->query->aggregateByLocation();
        $bySizeRows      = $this->query->aggregateBySize();

        $byTier = $this->buildGroupStats(
            $byTierRows,
            $totalHospitals,
            $totalParticipantHospitals,
            $totalAllocations,
            $totalParticipantAllocations
        );

        $byLocation = $this->buildGroupStats(
            $byLocationRows,
            $totalHospitals,
            $totalParticipantHospitals,
            $totalAllocations,
            $totalParticipantAllocations
        );

        $bySize = $this->buildGroupStats(
            $bySizeRows,
            $totalHospitals,
            $totalParticipantHospitals,
            $totalAllocations,
            $totalParticipantAllocations
        );

        return new HospitalCompositionStats(
            totalHospitals: $totalHospitals,
            totalParticipantHospitals: $totalParticipantHospitals,
            totalAllocations: $totalAllocations,
            totalParticipantAllocations: $totalParticipantAllocations,
            byTier: $byTier,
            byLocation: $byLocation,
            bySize: $bySize,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return HospitalGroupStats[]
     */
    private function buildGroupStats(
        array $rows,
        int $totalHospitals,
        int $totalParticipantHospitals,
        int $totalAllocations,
    ): array {
        $groups = [];

        foreach ($rows as $row) {
            $hospitalCount             = (int) $row['hospital_count'];
            $participantHospitalCount  = (int) $row['participant_hospital_count'];
            $allocationCount           = (int) $row['allocation_count'];

            $groups[] = new HospitalGroupStats(
                groupKey: (string) $row['group_key'],
                groupLabel: (string) $row['group_label'],
                hospitalCount: $hospitalCount,
                hospitalShare: $totalHospitals > 0 ? $hospitalCount / $totalHospitals : 0.0,
                participantHospitalCount: $participantHospitalCount,
                participantHospitalShare: $totalParticipantHospitals > 0 ? $participantHospitalCount / $totalParticipantHospitals : 0.0,
                avgBeds: $row['avg_beds'] !== null ? (float) $row['avg_beds'] : null,
                sdBeds: $row['sd_beds'] !== null ? (float) $row['sd_beds'] : null,
                varBeds: $row['var_beds'] !== null ? (float) $row['var_beds'] : null,
                allocationCount: $allocationCount,
                allocationShare: $totalAllocations > 0 ? $allocationCount / $totalAllocations : 0.0,
            );
        }

        return $groups;
    }
}
