<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightSearchService
{
    public const int MIN_QUERY_LENGTH = 2;

    public const int PER_DIMENSION_LIMIT = 5;

    public const int MAX_RESULTS = 20;

    public function __construct(
        private InsightDimensionRegistry $registry,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    /**
     * @return list<InsightSearchHit>
     */
    public function search(string $query, Request $request, ?InsightDimensionKey $onlyDimension = null): array
    {
        $term = trim($query);
        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $providers = $onlyDimension instanceof InsightDimensionKey
            ? [$this->registry->get($onlyDimension)]
            : $this->registry->all();

        $hits = [];
        foreach ($providers as $provider) {
            foreach ($provider->searchEntities($term, self::PER_DIMENSION_LIMIT) as $entity) {
                $hits[] = new InsightSearchHit(
                    $provider->key(),
                    $entity['id'],
                    $entity['label'],
                    $this->navigationUrlBuilder->build(
                        $request,
                        'app_stats_insights_show',
                        [
                            'dimension' => $provider->key()->value,
                            'id' => $entity['id'],
                        ],
                        ['q', 'sort', 'page', 'limit', 'view'],
                    ),
                    $entity['contextLabel'],
                );

                if (\count($hits) >= self::MAX_RESULTS) {
                    return $hits;
                }
            }
        }

        return $hits;
    }
}
