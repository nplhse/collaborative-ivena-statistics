<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\Application;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkCriteria;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class BenchmarkCriteriaFactory
{
    public function __construct(
        private StatisticsScopeResolver $statisticsScopeResolver,
        private TranslatorInterface $translator,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private HospitalCohortLabelResolver $hospitalCohortLabelResolver,
        private StateRepository $stateRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
    ) {
    }

    public function create(StatisticsContext $context, StatisticsFilter $comparisonFilter): BenchmarkCriteria
    {
        return new BenchmarkCriteria(
            $this->statisticsScopeResolver->resolveCriteria($context),
            $this->statisticsScopeResolver->resolveCriteria(
                new StatisticsContext($context->user, $comparisonFilter),
            ),
            StatisticsPeriodResolver::resolve($context->filter),
            StatisticsPeriodResolver::resolve($comparisonFilter),
            $this->scopeLabel($context->filter, $context->user),
            $this->scopeLabel($comparisonFilter, $context->user),
            $this->periodLabel($context->filter),
            $this->periodLabel($comparisonFilter),
        );
    }

    private function periodLabel(StatisticsFilter $filter): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all', [], 'statistics'),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time', [], 'statistics'),
            StatisticsFilterPeriod::Year => (string) ($filter->referenceYear ?? (int) $now->format('Y')),
            StatisticsFilterPeriod::Quarter => $this->translator->trans(
                'stats.dashboard.heading.quarter',
                [
                    'quarter' => (string) ($filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3)),
                    'year' => (string) ($filter->referenceYear ?? $now->format('Y')),
                ],
                'statistics',
            ),
            StatisticsFilterPeriod::Month => sprintf(
                '%04d-%02d',
                $filter->referenceYear ?? (int) $now->format('Y'),
                $filter->referenceMonth ?? (int) $now->format('n'),
            ),
        };
    }

    private function scopeLabel(StatisticsFilter $filter, ?User $user): string
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public', [], 'statistics'),
            StatisticsFilterScope::MyHospitals => $this->hospitalScopeLabelResolver->groupLabel($user),
            StatisticsFilterScope::Hospital => null !== $filter->hospitalId
                ? sprintf('Hospital %d', $filter->hospitalId)
                : $this->translator->trans('stats.filter.hospital.choose', [], 'statistics'),
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->hospitalCohortLabelResolver->label($filter->cohortType)
                : $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics'),
            StatisticsFilterScope::State => $this->stateLabel($filter->stateId),
            StatisticsFilterScope::DispatchArea => $this->dispatchAreaLabel($filter->dispatchAreaId),
        };
    }

    private function stateLabel(?int $stateId): string
    {
        if (null === $stateId) {
            return $this->translator->trans('stats.filter.scope.state', [], 'statistics');
        }
        $state = $this->stateRepository->findById($stateId);

        return $state?->getName() ?? sprintf('State %d', $stateId);
    }

    private function dispatchAreaLabel(?int $dispatchAreaId): string
    {
        if (null === $dispatchAreaId) {
            return $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics');
        }
        $area = $this->dispatchAreaRepository->findById($dispatchAreaId);

        return $area?->getName() ?? sprintf('Dispatch area %d', $dispatchAreaId);
    }
}
