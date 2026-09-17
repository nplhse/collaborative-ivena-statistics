<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use App\Allocation\Application\Explore\ExploreShowUrlResolver;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\TopEntityQuery;
use App\Statistics\Application\TopIndicationGroupsQuery;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class InsightValueDirectoryService
{
    private const int COUNT_FETCH_LIMIT = 10_000;

    public function __construct(
        private TopEntityQuery $topEntityQuery,
        private TopIndicationGroupsQuery $topIndicationGroupsQuery,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private ExploreShowUrlResolver $exploreShowUrlResolver,
    ) {
    }

    /**
     * @return list<InsightValueRow>
     */
    public function topValues(
        InsightDimensionProviderInterface $provider,
        StatisticsContext $context,
        Request $request,
        int $limit,
    ): array {
        $page = $this->list($provider, $context, $request, null, 'frequency', 1, $limit, clampPageSize: false);

        return $page->rows;
    }

    public function list(
        InsightDimensionProviderInterface $provider,
        StatisticsContext $context,
        Request $request,
        ?string $search,
        string $sort,
        int $page,
        int $limit,
        bool $clampPageSize = true,
    ): InsightDirectoryPage {
        $limit = $clampPageSize
            ? (\in_array($limit, [25, 50, 100], true) ? $limit : 25)
            : max(1, min($limit, self::COUNT_FETCH_LIMIT));
        $page = max(1, $page);
        $sort = 'alpha' === $sort ? 'alpha' : 'frequency';

        $entities = $provider->listEntities($search);
        $countsById = $this->countsById($provider, $context);
        $totalAllocations = $this->totalAllocations($provider, $context);

        $rows = [];
        foreach ($entities as $entity) {
            $count = $countsById[$entity['id']] ?? 0;
            $shareDisplay = $totalAllocations > 0
                ? sprintf('%.1f%%', round(100 * $count / $totalAllocations, 1))
                : '0.0%';

            $rows[] = new InsightValueRow(
                $entity['id'],
                $entity['label'],
                $count,
                $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_insights_show',
                    [
                        'dimension' => $provider->key()->value,
                        'id' => $entity['id'],
                    ],
                    ['q', 'sort', 'page', 'limit', 'view'],
                ),
                $entity['code'],
                $entity['publicId'],
                $shareDisplay,
                null,
                $entity['contextLabel'],
                $this->exploreShowUrlResolver->resolveUrlForClass(
                    $provider->entityFqcn(),
                    $entity['publicId'],
                ),
            );
        }

        if ('alpha' === $sort) {
            usort(
                $rows,
                static fn (InsightValueRow $a, InsightValueRow $b): int => strcasecmp($a->label, $b->label),
            );
        } else {
            usort(
                $rows,
                static function (InsightValueRow $a, InsightValueRow $b): int {
                    if ($a->count === $b->count) {
                        return strcasecmp($a->label, $b->label);
                    }

                    return $b->count <=> $a->count;
                },
            );
        }

        $numResults = \count($rows);
        $lastPage = max(1, (int) ceil($numResults / $limit));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $limit;
        $paged = \array_slice($rows, $offset, $limit);

        $rankStart = $offset + 1;
        $ranked = [];
        foreach ($paged as $index => $row) {
            $ranked[] = new InsightValueRow(
                $row->id,
                $row->label,
                $row->count,
                $row->url,
                $row->code,
                $row->publicId,
                $row->shareDisplay,
                $rankStart + $index,
                $row->contextLabel,
                $row->exploreUrl,
            );
        }

        return new InsightDirectoryPage($ranked, $totalAllocations, $page, $limit, $numResults);
    }

    /**
     * @return array<int, int>
     */
    private function countsById(InsightDimensionProviderInterface $provider, StatisticsContext $context): array
    {
        if (InsightDimensionKey::IndicationGroups === $provider->key()) {
            $data = $this->topIndicationGroupsQuery->fetch($context, self::COUNT_FETCH_LIMIT);
            $counts = [];
            foreach ($data['rows'] as $row) {
                $counts[$row['groupId']] = $row['count'];
            }

            return $counts;
        }

        $data = $this->topEntityQuery->fetch(
            $context,
            self::COUNT_FETCH_LIMIT,
            $provider->key()->projectionJoinProperty(),
            $provider->entityFqcn(),
            requireJoinedEntity: true,
        );

        $counts = [];
        foreach ($data['rows'] as $row) {
            if (null === $row['entityId']) {
                continue;
            }
            $counts[$row['entityId']] = $row['count'];
        }

        return $counts;
    }

    private function totalAllocations(InsightDimensionProviderInterface $provider, StatisticsContext $context): int
    {
        if (InsightDimensionKey::IndicationGroups === $provider->key()) {
            return $this->topIndicationGroupsQuery->fetch($context, 1)['totalAllocations'];
        }

        return $this->topEntityQuery->fetch(
            $context,
            1,
            $provider->key()->projectionJoinProperty(),
            $provider->entityFqcn(),
            requireJoinedEntity: true,
        )['totalAllocations'];
    }
}
