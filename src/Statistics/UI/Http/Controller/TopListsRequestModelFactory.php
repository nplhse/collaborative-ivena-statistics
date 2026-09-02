<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListLimitPolicy;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;

final readonly class TopListsRequestModelFactory
{
    public function __construct(
        private TopListLimitPolicy $topListLimitPolicy,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function fromQuery(array $query, string $topListKey = ''): TopListsRequestModel
    {
        $page = filter_var($query[StatisticsQueryKeys::PAGE] ?? 1, FILTER_VALIDATE_INT);
        $compareRaw = $query[StatisticsQueryKeys::COMPARE] ?? null;

        return new TopListsRequestModel(
            $topListKey,
            $this->topListLimitPolicy->normalize($query['limit'] ?? null),
            false !== $page ? max(1, $page) : 1,
            '1' === (string) $compareRaw || 1 === $compareRaw || true === $compareRaw,
        );
    }
}
