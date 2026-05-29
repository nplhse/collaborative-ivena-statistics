<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class StatisticsFilterInputFactory
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public function fromQuery(InputBag $query, ?User $user): StatisticsFilterInput
    {
        $defaultScope = $user instanceof User && $this->hospitalAccess->canUseMyHospitalsScope($user)
            ? StatisticsFilterScope::MyHospitals->value
            : StatisticsFilterScope::Public->value;

        return new StatisticsFilterInput(
            scope: $query->getString('scope', $defaultScope),
            hospital: $query->getString('hospital'),
            cohort: $query->getString('cohort'),
            state: $query->getString('state'),
            dispatchArea: $query->getString(StatisticsQueryKeys::DISPATCH_AREA),
            period: $query->getString('period', StatisticsFilterPeriod::AllTime->value),
            year: $query->get('year'),
            month: $query->get('month'),
            quarter: $query->get(StatisticsQueryKeys::QUARTER),
            hasScopeQueryParameter: $query->has('scope'),
        );
    }
}
