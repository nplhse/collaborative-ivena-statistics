<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

final class DistributionSectionNavProvider
{
    /** @return list<array{route: string, label: string}> */
    public function sections(): array
    {
        return [
            ['route' => 'app_stats_distribution_urgency', 'label' => 'statistics.distribution.section.urgency'],
            ['route' => 'app_stats_distribution_gender', 'label' => 'statistics.distribution.section.gender'],
            ['route' => 'app_stats_distribution_age', 'label' => 'statistics.distribution.section.age'],
            ['route' => 'app_stats_distribution_assignment', 'label' => 'statistics.distribution.section.assignment'],
            ['route' => 'app_stats_distribution_occasion', 'label' => 'statistics.distribution.section.occasion'],
            ['route' => 'app_stats_distribution_time', 'label' => 'statistics.distribution.section.time'],
            ['route' => 'app_stats_distribution_transport_time', 'label' => 'statistics.distribution.section.transport_time'],
            ['route' => 'app_stats_distribution_resources', 'label' => 'statistics.distribution.section.resources'],
            ['route' => 'app_stats_distribution_traits', 'label' => 'statistics.distribution.section.traits'],
        ];
    }
}
