<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality;

final class DataQualityThresholds
{
    public const float COVERAGE_LOW_MAX = 0.20;
    public const float COVERAGE_HIGH_MIN = 0.90;

    public const float REPRESENTATIVENESS_HIGH_MAX = 0.10;
    public const float REPRESENTATIVENESS_MEDIUM_MAX = 0.25;

    public const float SHARE_LOW_MAX = 0.50;
    public const float SHARE_MEDIUM_MAX = 0.80;

    public const float OVERALL_LOW_MAX = 1.5;
    public const float OVERALL_MEDIUM_MAX = 2.5;

    public const int MIN_PARTICIPANTS_PER_CELL = 5;
    public const int MIN_ALLOCATIONS_PER_HOSPITAL = 100;
    public const int MIN_POPULATION_FOR_DIMENSIONS = 5;

    /** Below this count of populated subgroup cells, rating uses cell share instead of population share. */
    public const int MIN_RELEVANT_SUBGROUP_CELLS_FOR_POPULATION_SHARE = 5;

    /** @var list<int> */
    public const array ALLOCATION_HISTOGRAM_EDGES = [0, 25, 100, 250];
}
