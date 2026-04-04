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
        ];
    }
}
