<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

use App\Analytics\Domain\Enum\FeatureArea;

final class FeatureAreaResolver
{
    public function resolve(?string $routeName): FeatureArea
    {
        if (null === $routeName || '' === $routeName) {
            return FeatureArea::Other;
        }

        if ('app_default' === $routeName) {
            return FeatureArea::Home;
        }

        if (str_starts_with($routeName, 'app_stats_dashboard')) {
            return FeatureArea::Dashboard;
        }

        if (str_starts_with($routeName, 'app_stats_analysis')) {
            return FeatureArea::Analysis;
        }

        if (str_starts_with($routeName, 'app_stats_')) {
            return FeatureArea::Statistics;
        }

        if (str_starts_with($routeName, 'app_explore_')) {
            return FeatureArea::Explore;
        }

        if (str_starts_with($routeName, 'app_import_')) {
            return FeatureArea::Import;
        }

        if (str_contains($routeName, 'export')) {
            return FeatureArea::Export;
        }

        if (str_starts_with($routeName, 'app_admin_')) {
            return FeatureArea::Admin;
        }

        if (str_starts_with($routeName, 'app_blog_')) {
            return FeatureArea::Blog;
        }

        if (str_starts_with($routeName, 'app_page_')) {
            return FeatureArea::Pages;
        }

        return FeatureArea::Other;
    }
}
