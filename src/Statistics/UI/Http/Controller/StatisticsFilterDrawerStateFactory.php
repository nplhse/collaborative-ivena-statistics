<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\Application\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsFilterDrawerStateFactory
{
    /**
     * @return array{
     *   values: array<string, string>,
     *   activeCount: int
     * }
     */
    public function fromRequest(Request $request): array
    {
        $values = [];
        $activeCount = 0;

        foreach (StatisticsQueryKeys::DRAWER_FILTERS as $key) {
            $value = trim($request->query->getString($key));
            if ('age_group' === $key && '' !== $value && !StatisticsAgeGroupFilter::isKnown($value)) {
                // Unknown age groups set no restriction, so the drawer shows no filter for them either (#1724).
                $value = '';
            }
            $values[$key] = $value;
            if ('' !== $value) {
                ++$activeCount;
            }
        }

        return [
            'values' => $values,
            'activeCount' => $activeCount,
        ];
    }
}
