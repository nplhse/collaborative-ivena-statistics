<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListLimitPolicy;

final readonly class TopListsRequestModelFactory
{
    public function __construct(
        private TopListLimitPolicy $topListLimitPolicy,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function fromQuery(array $query): TopListsRequestModel
    {
        $topListKey = isset($query['report']) ? (string) $query['report'] : '';

        return new TopListsRequestModel(
            $topListKey,
            $this->topListLimitPolicy->normalize($query['limit'] ?? null),
        );
    }
}
