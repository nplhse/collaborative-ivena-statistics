<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

final class DistributionPageConfigFactory
{
    public const string PAGE_URGENCY = 'urgency';

    public const string PAGE_GENDER = 'gender';

    public const string PAGE_AGE_COHORT = 'age_cohort';

    public function forPageId(string $pageId): DistributionPageConfig
    {
        return match ($pageId) {
            self::PAGE_GENDER => new DistributionPageConfig(
                routeName: 'app_stats_distribution_gender',
                panels: [DistributionPanelPresets::gender()],
            ),
            self::PAGE_AGE_COHORT => new DistributionPageConfig(
                routeName: 'app_stats_distribution_age',
                panels: [DistributionPanelPresets::ageCohort()],
            ),
            self::PAGE_URGENCY => new DistributionPageConfig(
                routeName: 'app_stats_distribution_urgency',
                panels: [DistributionPanelPresets::urgency()],
            ),
            default => throw new \InvalidArgumentException('Unknown distribution page: '.$pageId),
        };
    }
}
