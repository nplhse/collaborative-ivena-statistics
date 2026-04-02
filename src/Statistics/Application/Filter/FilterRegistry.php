<?php

declare(strict_types=1);

namespace App\Statistics\Application\Filter;

use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;

final class FilterRegistry
{
    /**
     * @var array<string, FilterDefinition>
     */
    private array $definitions;

    public function __construct()
    {
        $tierChoices = array_map(
            static fn (AllocationStatsHospitalTierProjectionCode $c): int => $c->value,
            AllocationStatsHospitalTierProjectionCode::cases(),
        );

        $locationChoices = array_map(
            static fn (AllocationStatsHospitalLocationProjectionCode $c): int => $c->value,
            AllocationStatsHospitalLocationProjectionCode::cases(),
        );

        $this->definitions = [
            'date_range' => new FilterDefinition(
                key: 'date_range',
                type: 'date_range',
                field: 'created_at',
                defaultValue: 'all_cases',
            ),
            'hospital_tier' => new FilterDefinition(
                key: 'hospital_tier',
                type: 'select',
                field: 'hospital_tier_code',
                defaultValue: [],
                multiple: true,
                choices: $tierChoices,
            ),
            'hospital_location' => new FilterDefinition(
                key: 'hospital_location',
                type: 'select',
                field: 'hospital_location_code',
                defaultValue: [],
                multiple: true,
                choices: $locationChoices,
            ),
        ];
    }

    public function get(string $key): FilterDefinition
    {
        if (!isset($this->definitions[$key])) {
            throw new \InvalidArgumentException('Unknown filter: '.$key);
        }

        return $this->definitions[$key];
    }
}
