<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class BenchmarkDefaultResolver
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
    ) {
    }

    /**
     * @return array{query: array<string, string>}|null
     */
    public function maybeRedirectPayload(Request $request, ?User $user): ?array
    {
        if ($request->query->has(StatisticsQueryKeys::COMPARISON_SCOPE)) {
            return null;
        }

        if ($user instanceof User && $this->hospitalAccess->canUseBenchmarkingScope($user)) {
            return [
                'query' => [
                    'scope' => StatisticsFilterScope::MyHospitals->value,
                    'period' => StatisticsFilterPeriod::All->value,
                    StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::HospitalCohort->value,
                    StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::AllTime->value,
                ],
            ];
        }

        return [
            'query' => [
                'scope' => StatisticsFilterScope::Public->value,
                'period' => StatisticsFilterPeriod::All->value,
                StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::Public->value,
                StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::AllTime->value,
            ],
        ];
    }
}
