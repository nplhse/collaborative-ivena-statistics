<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;

/**
 * Central mapping between Explore catalog dimensions and statistics top lists.
 */
final readonly class TopListCatalogCrossReference
{
    public function topListKeyForDimension(CatalogDimensionKey $dimension): ?string
    {
        return match ($dimension) {
            CatalogDimensionKey::Indication => 'top_diagnoses',
            CatalogDimensionKey::Department => 'top_departments',
            CatalogDimensionKey::Speciality => 'top_specialities',
            CatalogDimensionKey::Assignment => 'top_assignments',
            CatalogDimensionKey::Occasion => 'top_occasions',
            CatalogDimensionKey::Infection => 'top_infections',
            default => null,
        };
    }

    public function catalogListRoute(string $topListKey): ?string
    {
        return match ($topListKey) {
            'top_diagnoses', 'top_secondary_diagnoses' => 'app_explore_indication_list',
            'top_departments' => 'app_explore_department_list',
            'top_specialities' => 'app_explore_speciality_list',
            'top_assignments' => 'app_explore_assignment_list',
            'top_occasions' => 'app_explore_occasion_list',
            'top_infections' => 'app_explore_infection_list',
            default => null,
        };
    }

    public function labelRowTarget(string $topListKey, ?string $publicId): ?StatisticWidgetNavigationTarget
    {
        return match ($topListKey) {
            'top_diagnoses', 'top_secondary_diagnoses' => $this->catalogShowTarget('app_explore_indication_show', $publicId),
            'top_departments' => $this->catalogShowTarget('app_explore_department_show', $publicId),
            'top_specialities' => $this->catalogShowTarget('app_explore_speciality_show', $publicId),
            'top_assignments' => $this->catalogShowTarget('app_explore_assignment_show', $publicId),
            'top_occasions' => $this->catalogShowTarget('app_explore_occasion_show', $publicId),
            'top_infections' => $this->catalogShowTarget('app_explore_infection_show', $publicId),
            default => null,
        };
    }

    private function catalogShowTarget(string $route, ?string $publicId): ?StatisticWidgetNavigationTarget
    {
        if (null === $publicId || '' === $publicId) {
            return null;
        }

        return new StatisticWidgetNavigationTarget(
            'stats.top_lists.nav.catalog_entry',
            $route,
            ['publicId' => $publicId],
            mergeRequestQuery: false,
        );
    }
}
