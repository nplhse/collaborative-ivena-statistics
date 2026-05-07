<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterNotice;
use App\Statistics\Application\DTO\StatisticsFilterScope;
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
        $query['scope'] = StatisticsFilterScope::Public->value;
        unset($query['cohort'], $query['hospital']);

        return [
            'notice' => $filter->notice instanceof StatisticsFilterNotice ? $filter->notice : null,
            'query' => $query,
        ];
    }
}
