<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterNotice;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsPublicScopeRedirector
{
    /**
     * @return array{notice:StatisticsFilterNotice|null,query:array<string,mixed>}|null
     */
    public function maybeRedirectPayload(
        Request $request,
        StatisticsFilter $filter,
    ): ?array {
        if (!$filter->requiresPublicRedirect) {
            return null;
        }

        $query = $request->query->all();
        $query[StatisticsQueryKeys::SCOPE] = StatisticsFilterScope::Public->value;
        foreach (StatisticsQueryKeys::REMOVE_SCOPE_DEPENDENT as $key) {
            unset($query[$key]);
        }

        return [
            'notice' => $filter->notice instanceof StatisticsFilterNotice ? $filter->notice : null,
            'query' => $query,
        ];
    }
}
