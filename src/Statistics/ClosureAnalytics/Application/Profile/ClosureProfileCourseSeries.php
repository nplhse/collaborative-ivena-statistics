<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileCourseSeries
{
    /**
     * @param list<ClosureProfileCoursePoint> $points
     */
    public function __construct(
        public int $specialityId,
        public string $name,
        public bool $combined,
        public array $points,
    ) {
    }
}
