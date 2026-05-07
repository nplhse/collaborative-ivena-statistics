<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use Symfony\Component\HttpFoundation\InputBag;

final class StatisticsFilterInputFactory
{
    /**
     * @param InputBag<string> $query
     */
    public function fromQuery(InputBag $query): StatisticsFilterInput
    {
        return new StatisticsFilterInput(
            $query->getString('scope', StatisticsFilterScope::MyHospitals->value),
            $query->getString('hospital'),
            $query->getString('cohort'),
            $query->getString('state'),
            $query->getString('period', StatisticsFilterPeriod::AllTime->value),
            $query->get('year'),
            $query->get('month'),
            $query->has('scope'),
        );
    }
}
