<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeDetailView
{
    /**
     * @param list<ClosureVolumeWindowResult>                        $windows
     * @param array<string, mixed>                                   $chart
     * @param list<array{start: string, end: string, label: string}> $otherClosures
     * @param array{pre: string, post: string}|null                  $comparison
     * @param list<array{name: string, careLevels: list<string>}>    $affectedDepartments
     */
    public function __construct(
        public ClosureVolumeStratum $stratum,
        public bool $built,
        public bool $applicable,
        public bool $extendsBeyondPeriod,
        public bool $ongoing,
        public bool $wholeDepartment,
        public ?ClosureVolumeWindowResult $during,
        public array $windows,
        public array $chart,
        public array $otherClosures,
        public ?array $comparison,
        public int $referenceWeeks,
        public int $minimumReferenceSlots,
        public array $affectedDepartments = [],
    ) {
    }
}
