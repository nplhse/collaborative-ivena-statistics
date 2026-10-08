<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileView
{
    /**
     * @param list<float>                      $individualMinutes
     * @param list<ClosureProfileCareCount>    $careLevels
     * @param list<ClosureProfileMember>       $members
     * @param list<ClosureProfileDepartment>   $departments
     * @param list<ClosureProfileCourseSeries> $startCourse
     * @param list<ClosureProfileCourseSeries> $endCourse
     * @param list<list<int|null>>             $heatmapMatrix
     */
    public function __construct(
        public ClosureProfileRef $ref,
        public string $title,
        public string $hospitalName,
        public string $hospitalPublicId,
        public int $eventCount,
        public int $usableEventCount,
        public ?float $medianMinutes,
        public ?float $q1Minutes,
        public ?float $q3Minutes,
        public ?float $meanMinutes,
        public array $individualMinutes,
        public bool $showIndividuals,
        public array $careLevels,
        public array $members,
        public array $departments,
        public ClosureProfileCoverage $coverage,
        public array $startCourse,
        public array $endCourse,
        public ClosureProfilePhaseTotals $phase,
        public array $heatmapMatrix,
        public bool $specialityProfile,
    ) {
    }
}
