<?php

declare(strict_types=1);

namespace App\Statistics\UI\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Statistics entry of the primary menu and the statistics sidebar (ADR 035).
 *
 * Groups follow the analytical question, not the build order. Every page of the area is mapped to exactly
 * one entry by its route name (own route or detail route); frames, exports and other non-page routes are
 * left out, they mark only the area. The Insights dimensions share one route and are told apart by the
 * `dimension` parameter.
 *
 * @psalm-suppress UnusedClass Tagged via NavigationProviderInterface.
 */
final readonly class StatisticsNavigationProvider implements NavigationProviderInterface
{
    private const string DOMAIN = 'statistics';

    /**
     * Pages that belong to one Insights dimension although their route has no `dimension` parameter.
     *
     * @var array<string, list<string>>
     */
    private const array DIMENSION_DETAIL_ROUTES = [
        'indications' => ['app_stats_indication_dashboard', 'app_stats_indication_insights', 'app_stats_indication_compare'],
        'indication-groups' => ['app_stats_indication_group_dashboard'],
    ];

    public function __construct(
        private InsightDimensionRegistry $insightDimensions,
    ) {
    }

    #[\Override]
    public function groups(Menu $menu): array
    {
        if (Menu::Statistics !== $menu) {
            return [];
        }

        return [
            new NavGroup('overview', null, 10),
            new NavGroup('allocations', $this->label('stats.nav.group.allocations'), 20),
            new NavGroup('comparisons', $this->label('stats.nav.group.comparisons'), 30),
            new NavGroup('capacity', $this->label('stats.nav.group.capacity'), 40),
            new NavGroup(Menu::STATISTICS_GROUP_CLINICAL, $this->label('stats.nav.group.clinical'), 50),
            new NavGroup('own', $this->label('stats.nav.group.own'), 60),
            new NavGroup('standalone', null, 70),
        ];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        return match ($menu) {
            Menu::Primary => [$this->primaryEntry()],
            Menu::Statistics => $this->sidebarItems(),
            default => [],
        };
    }

    private function primaryEntry(): NavItem
    {
        return new NavItem(
            id: 'statistics',
            parentId: Menu::PRIMARY_GROUP,
            label: new TranslatableMessage('link.statistics', [], 'shared'),
            route: 'app_stats_dashboard',
            icon: 'tabler:graph',
            grantedAttribute: 'ROLE_USER',
            weight: 20,
            areaRoutePrefix: 'app_stats',
        );
    }

    /**
     * @return list<NavItem>
     */
    private function sidebarItems(): array
    {
        return [
            // Overview
            $this->entry('dashboard', 'overview', 'link.stats.dashboard', 'app_stats_dashboard', 'tabler:dashboard', -100, ['app_stats_overview_self_benchmark', 'app_stats_overview_top_reports']),
            $this->entry('reports', 'overview', 'link.stats.reports', 'app_stats_reports', 'tabler:report-analytics', 10, ['app_stats_reports_show']),
            $this->entry('top-lists', 'overview', 'link.stats.top_lists', 'app_stats_top_lists', 'tabler:list-numbers', 20, ['app_stats_top_lists_show']),

            // Allocations: Insights with its dimensions
            new NavItem(id: 'insights', parentId: 'allocations', label: new TranslatableMessage('link.stats.insights', [], 'shared'), icon: 'tabler:chart-bar'),
            new NavItem(id: 'insights-overview', parentId: 'insights', label: $this->label('stats.insights.subnav.overview'), route: 'app_stats_insights', detailRoutes: ['app_stats_insights_search'], weight: -100),
            new NavItem(id: 'insights-dimensions', parentId: 'insights', label: $this->label('stats.nav.insights_dimensions'), weight: 10),
            ...$this->dimensionItems(),
            new NavItem(id: 'insights-compare', parentId: 'insights', label: $this->label('stats.nav.insights_compare'), route: 'app_stats_insights_compare', weight: 20),

            // Comparisons
            $this->entry('benchmarking', 'comparisons', 'link.stats.benchmarking', 'app_stats_benchmarking', 'tabler:scale'),

            // Capacity and closures. Closure Analytics needs ROLE_PARTICIPANT (access_control and controllers).
            $this->entry('closed-department-assignments', 'capacity', 'link.stats.closed_department_assignments', 'app_stats_closed_department_assignments', 'tabler:door-off', 10, ['app_stats_closed_department_assignments_details', 'app_stats_closed_department_assignments_rankings']),
            new NavItem(id: 'closures', parentId: 'capacity', label: new TranslatableMessage('link.stats.closure_analytics', [], 'shared'), icon: 'tabler:calendar-off', grantedAttribute: 'ROLE_PARTICIPANT', weight: 20, sharedFilterState: true),
            new NavItem(id: 'closures-overview', parentId: 'closures', label: $this->label('stats.closure.tabs.overview'), route: 'app_stats_closure_analytics', grantedAttribute: 'ROLE_PARTICIPANT', detailRoutes: ['app_stats_closure_analytics_details', 'app_stats_closure_analytics_volume_burden'], weight: 10),
            new NavItem(id: 'closures-duration', parentId: 'closures', label: $this->label('stats.closure.tabs.duration'), route: 'app_stats_closure_analytics_duration', grantedAttribute: 'ROLE_PARTICIPANT', detailRoutes: ['app_stats_closure_analytics_interval'], weight: 20),
            new NavItem(id: 'closures-timeline', parentId: 'closures', label: $this->label('stats.closure.tabs.timeline'), route: 'app_stats_closure_analytics_timeline', grantedAttribute: 'ROLE_PARTICIPANT', weight: 30),
            new NavItem(id: 'closures-events', parentId: 'closures', label: $this->label('stats.closure.tabs.events'), route: 'app_stats_closure_analytics_events', grantedAttribute: 'ROLE_PARTICIPANT', detailRoutes: ['app_stats_closure_analytics_event'], weight: 40),
            new NavItem(id: 'closures-profiles', parentId: 'closures', label: $this->label('stats.closure.tabs.profiles'), route: 'app_stats_closure_analytics_profiles', grantedAttribute: 'ROLE_PARTICIPANT', detailRoutes: [
                'app_stats_closure_profile_department',
                'app_stats_closure_profile_department_scope',
                'app_stats_closure_profile_group',
                'app_stats_closure_profile_hospital',
                'app_stats_closure_profile_speciality',
                'app_stats_closure_profile_speciality_scope',
                'app_stats_closure_profile_unit',
            ], weight: 50),

            // Clinical data: entries come from encounter schema plugins (Menu::STATISTICS_GROUP_CLINICAL).

            // Own analyses
            $this->entry('analysis-explorer', 'own', 'link.stats.analysis_explorer', 'app_stats_analysis_library', 'tabler:chart-dots', 0, [
                'app_stats_analysis',
                'app_stats_analysis_explorer',
                'app_stats_analysis_explorer_view',
                'app_stats_analysis_assistant',
                'app_stats_analytics',
                'app_stats_analytics_builder',
                'app_stats_analytics_library',
                'app_stats_analytics_saved',
                'app_stats_analytics_view',
                'app_stats_generic_analysis',
            ]),

            // Standalone entries, not yet in a group
            $this->entry('case-flow', 'standalone', 'link.stats.case_flow', 'app_stats_case_flow', 'tabler:route', 10, ['app_stats_case_flow_segment_profile']),
            $this->entry('hospital-population', 'standalone', 'link.stats.hospital_population', 'app_stats_hospital_population', 'tabler:building-hospital', 20, ['app_stats_hospital_population_legacy_characteristics']),
        ];
    }

    /**
     * One entry per Insights dimension (including the nested Indication groups), ordered alphabetically by
     * label.
     *
     * @return list<NavItem>
     */
    private function dimensionItems(): array
    {
        $items = [];
        foreach ($this->insightDimensions->all() as $provider) {
            $key = $provider->key();
            $items[] = new NavItem(
                id: 'insights-dimension-'.$key->value,
                parentId: 'insights-dimensions',
                label: $this->label($provider->labelTranslationKey()),
                route: 'app_stats_insights_dimension',
                routeParameters: ['dimension' => $key->value],
                detailRoutes: ['app_stats_insights_show', 'app_stats_insights_compare_legacy', ...self::DIMENSION_DETAIL_ROUTES[$key->value] ?? []],
            );
        }

        return $items;
    }

    /**
     * @param list<string> $detailRoutes
     */
    private function entry(string $id, string $groupId, string $sharedLabelKey, string $route, string $icon, int $weight = 0, array $detailRoutes = []): NavItem
    {
        return new NavItem(
            id: $id,
            parentId: $groupId,
            label: new TranslatableMessage($sharedLabelKey, [], 'shared'),
            route: $route,
            icon: $icon,
            detailRoutes: $detailRoutes,
            weight: $weight,
        );
    }

    private function label(string $key): TranslatableMessage
    {
        return new TranslatableMessage($key, [], self::DOMAIN);
    }
}
