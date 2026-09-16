<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightsSubnavViewModelFactory
{
    public function __construct(
        private InsightDimensionRegistry $registry,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    /**
     * @return array{
     *     overviewUrl: string,
     *     overviewActive: bool,
     *     tabs: list<array{key: string, labelKey: string, url: string, active: bool}>
     * }
     */
    public function create(Request $request, ?InsightDimensionKey $activeDimension): array
    {
        $activeKey = $activeDimension?->compareFamily();
        $removeKeys = ['q', 'sort', 'page', 'limit', 'view', 'id', 'dimension'];

        $tabs = [];
        foreach ($this->registry->primaryNav() as $provider) {
            $key = $provider->key();
            $tabs[] = [
                'key' => $key->value,
                'labelKey' => $provider->labelTranslationKey(),
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_insights_dimension',
                    ['dimension' => $key->value],
                    $removeKeys,
                ),
                'active' => $activeKey === $key,
            ];
        }

        return [
            'overviewUrl' => $this->navigationUrlBuilder->build($request, 'app_stats_insights', [], $removeKeys),
            'overviewActive' => !$activeDimension instanceof InsightDimensionKey,
            'tabs' => $tabs,
        ];
    }
}
