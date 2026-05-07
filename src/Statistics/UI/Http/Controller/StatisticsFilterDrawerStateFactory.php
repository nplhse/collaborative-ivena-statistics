<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
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
