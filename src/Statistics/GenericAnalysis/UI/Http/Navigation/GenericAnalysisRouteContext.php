<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Navigation;

final readonly class GenericAnalysisRouteContext
{
    private const string GENERIC_ROUTE = 'app_stats_generic_analysis';

    public const string ANALYTICS_VIEW_ROUTE = 'app_stats_analytics_view';

    public const string ANALYTICS_BUILDER_ROUTE = 'app_stats_analytics_builder';

    private const string ANALYTICS_SAVED_ROUTE = 'app_stats_analytics_saved';

    /**
     * @param array<string, string> $routeParams
     */
    public function __construct(
        public string $routeName,
        public array $routeParams,
    ) {
    }

    public static function forPreset(string $presetKey): self
    {
        return new self(self::GENERIC_ROUTE, ['presetKey' => $presetKey]);
    }

    public static function forAnalyticsView(string $viewKey): self
    {
        return new self(self::ANALYTICS_VIEW_ROUTE, ['viewKey' => $viewKey]);
    }

    public static function forBuilder(): self
    {
        return new self(self::ANALYTICS_BUILDER_ROUTE, []);
    }

    public function isBuilder(): bool
    {
        return self::ANALYTICS_BUILDER_ROUTE === $this->routeName;
    }

    public function usesDataSourceNavigationUrls(): bool
    {
        return $this->isBuilder()
            || self::ANALYTICS_VIEW_ROUTE === $this->routeName
            || self::ANALYTICS_SAVED_ROUTE === $this->routeName;
    }

    public static function forSavedView(int $savedViewId): self
    {
        return new self(self::ANALYTICS_SAVED_ROUTE, ['id' => (string) $savedViewId]);
    }
}
