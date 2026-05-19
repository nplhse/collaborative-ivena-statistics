<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\MaterializedView;

/**
 * Registry of statistics materialized view groups for install/refresh commands.
 */
final class StatisticsMaterializedViewGroups
{
    public const string OVERVIEW = 'overview';

    /** @var array<string, list<string>> */
    private const array GROUPS = [
        self::OVERVIEW => [
            'mv_projection_state_hospital_count',
            'mv_projection_dispatch_area_hospital_count',
            'mv_projection_hospital_dimensions',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function knownGroups(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * @return list<string>
     */
    public static function viewsForGroup(string $group): array
    {
        if (!isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException(sprintf('Unknown materialized view group "%s". Known groups: %s', $group, implode(', ', self::knownGroups())));
        }

        return self::GROUPS[$group];
    }

    /**
     * @param list<string> $groups empty = all groups
     *
     * @return list<string>
     */
    public static function viewsForGroups(array $groups): array
    {
        if ([] === $groups) {
            return self::allViews();
        }

        $views = [];
        foreach ($groups as $group) {
            foreach (self::viewsForGroup($group) as $viewName) {
                $views[] = $viewName;
            }
        }

        return array_values(array_unique($views));
    }

    /**
     * @return list<string>
     */
    public static function allViews(): array
    {
        return self::viewsForGroups(self::knownGroups());
    }
}
