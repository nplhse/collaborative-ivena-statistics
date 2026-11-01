<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

use App\Analytics\Domain\Enum\DeviceType;

/**
 * Decides whether a tracked request counts as an access (a normal page view, #950).
 *
 * Counted: GET/HEAD main request with a 2xx response to a page route, not a Turbo frame,
 * Live Component, XHR or prefetch request, and not from a recognised bot. Redirects, errors,
 * technical endpoints, lazily loaded frames and downloads are not counted.
 *
 * Definition: docs/04-features/analytics/usage-analytics.md#counted-accesses
 */
final class CountedAccessClassifier
{
    /**
     * Routes that never render a page: technical endpoints, background requests,
     * lazily loaded Turbo frames and partials, downloads.
     *
     * @var list<string>
     */
    public const array NON_PAGE_ROUTES = [
        // Technical endpoints
        'app_robots_txt',
        'app_xml_sitemap',
        'app_blog_rss',
        'app_health',
        'app_locale_switch',
        'app_error_preview',
        'ux_live_component',
        // Background requests (polling, JSON, widgets)
        'app_notification_poll',
        'app_notification_dropdown',
        'app_import_status',
        'app_feedback_widget',
        'app_data_table_preferences_save',
        // Lazily loaded Turbo frames and partials
        'app_dashboard_activity',
        'app_activity_timeline_feed',
        'app_explore_user_activity',
        'app_stats_overview_self_benchmark',
        'app_stats_overview_top_reports',
        'app_stats_isochrone_origin_map',
        'app_stats_case_flow_segment_profile',
        'app_stats_closed_department_assignments_card',
        'app_stats_closed_department_assignments_details',
        'app_stats_closed_department_assignments_rankings',
        // Downloads
        'app_import_source_file_download',
        'app_hospitals_export_allocations_download',
    ];

    /** @var list<string> */
    private const array NON_PAGE_ROUTE_PREFIXES = [
        'app_cookie_',
    ];

    /** @var list<string> */
    private const array NON_PAGE_ROUTE_SUFFIXES = [
        '_frame',
        '_drawer',
        '_export_csv',
    ];

    public function isCountedAccess(
        string $method,
        int $httpStatus,
        ?string $routeName,
        DeviceType $deviceType,
        bool $isFrameRequest,
        bool $isBackgroundRequest,
    ): bool {
        if (!\in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($isFrameRequest || $isBackgroundRequest) {
            return false;
        }

        return $this->isCountedStoredRequest($httpStatus, $routeName, $deviceType);
    }

    /**
     * Classification from the fields stored on `analytics_request`. Rows recorded before the
     * classification existed have no method and no request headers, so frame navigations on
     * page routes and non-GET requests with a 2xx response cannot be recognised for them.
     */
    public function isCountedStoredRequest(int $httpStatus, ?string $routeName, DeviceType $deviceType): bool
    {
        if ($httpStatus < 200 || $httpStatus > 299) {
            return false;
        }

        if (DeviceType::Bot === $deviceType) {
            return false;
        }

        return null !== $routeName && $this->isPageRoute($routeName);
    }

    private function isPageRoute(string $routeName): bool
    {
        return !\in_array($routeName, self::NON_PAGE_ROUTES, true)
            && array_all(self::NON_PAGE_ROUTE_PREFIXES, static fn (string $prefix): bool => !str_starts_with($routeName, $prefix))
            && array_all(self::NON_PAGE_ROUTE_SUFFIXES, static fn (string $suffix): bool => !str_ends_with($routeName, $suffix));
    }
}
