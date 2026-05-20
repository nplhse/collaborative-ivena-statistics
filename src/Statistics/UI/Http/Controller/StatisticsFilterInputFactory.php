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
            $query->getString('scope', $defaultScope),
            $query->getString('hospital'),
            $query->getString('cohort'),
            $query->getString('state'),
            $query->getString(StatisticsQueryKeys::DISPATCH_AREA),
            $query->getString('period', StatisticsFilterPeriod::AllTime->value),
            $query->get('year'),
            $query->get('month'),
            $query->has('scope'),
        );
    }
}
