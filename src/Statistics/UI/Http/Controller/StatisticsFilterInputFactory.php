<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilterInput;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\GenericAnalysis\Application\HospitalStatisticsScopeDefaultPolicy;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final readonly class StatisticsFilterInputFactory
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
        private HospitalStatisticsScopeDefaultPolicy $hospitalStatisticsScopeDefaultPolicy,
    ) {
    }

    public function fromRequest(Request $request, ?User $user): StatisticsFilterInput
    {
        return $this->fromQuery($request->query, $user, $request);
    }

    /**
     * @param InputBag<string> $query
     */
    public function fromQuery(InputBag $query, ?User $user, ?Request $request = null): StatisticsFilterInput
    {
        $defaultScope = $this->resolveDefaultScope($user, $request);

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

    private function resolveDefaultScope(?User $user, ?Request $request): string
    {
        if ($request instanceof Request && $this->hospitalStatisticsScopeDefaultPolicy->shouldDefaultToPublicScope($request)) {
            return StatisticsFilterScope::Public->value;
        }

        return $user instanceof User && $this->hospitalAccess->canUseMyHospitalsScope($user)
            ? StatisticsFilterScope::MyHospitals->value
            : StatisticsFilterScope::Public->value;
    }
}
