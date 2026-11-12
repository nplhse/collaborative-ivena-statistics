<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * One bucket of the visits time series: visits per arrival route and in total, each with
 * small-cell suppression. Shares are of the bucket's visits.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the overview templates.
 */
final readonly class TimeBucket
{
    /** @var array<string, OverviewCount> route => visits */
    public array $routes;

    public OverviewCount $total;

    /**
     * @param array<string, int> $visitsByRoute
     * @param list<string>       $routes
     */
    public function __construct(
        public \DateTimeImmutable $start,
        array $visitsByRoute,
        array $routes,
    ) {
        $total = array_sum($visitsByRoute);
        $counts = [];
        foreach ($routes as $route) {
            $counts[$route] = new OverviewCount($route, $visitsByRoute[$route] ?? 0, $total);
        }

        $this->routes = $counts;
        $this->total = new OverviewCount('total', $total, $total);
    }
}
