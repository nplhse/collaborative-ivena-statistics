<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Human-readable scope and period labels for Analysis Explorer summaries.
 */
final readonly class ExplorerAnalysisSummaryLabelResolver implements ExplorerAnalysisSummaryLabelResolverInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private HospitalCohortLabelResolver $hospitalCohortLabelResolver,
        private HospitalAccessInterface $hospitalAccess,
        private HospitalLookupInterface $hospitalLookup,
        private StateLookupInterface $stateLookup,
        private DispatchAreaLookupInterface $dispatchAreaLookup,
    ) {
    }

    #[\Override]
    public function scopeLabel(StatisticsFilter $filter, ?User $user, ?string $locale = null): string
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans(
                'stats.analysis_explorer.summary.scope.all_hospitals',
                [],
                'statistics',
                $locale,
            ),
            StatisticsFilterScope::MyHospitals => $this->myHospitalsLabel($user, $locale),
            StatisticsFilterScope::Hospital => $this->hospitalName($filter->hospitalId)
                ?? $this->translator->trans('stats.filter.hospital.choose', [], 'statistics', $locale),
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->hospitalCohortLabelResolver->label($filter->cohortType, $locale)
                : $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale),
            StatisticsFilterScope::State => $this->stateName($filter->stateId)
                ?? $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale),
            StatisticsFilterScope::DispatchArea => $this->dispatchAreaName($filter->dispatchAreaId)
                ?? $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale),
        };
    }

    #[\Override]
    public function periodLabel(StatisticsFilter $filter, ?string $locale = null): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans(
                'stats.filter.period.all',
                [],
                'statistics',
                $locale,
            ),
            StatisticsFilterPeriod::AllTime => $this->translator->trans(
                'stats.filter.period.all_time',
                [],
                'statistics',
                $locale,
            ),
            StatisticsFilterPeriod::Year => (string) ($filter->referenceYear ?? $now->format('Y')),
            StatisticsFilterPeriod::Quarter => $this->translator->trans(
                'stats.dashboard.heading.quarter',
                [
                    'quarter' => (string) ($filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3)),
                    'year' => (string) ($filter->referenceYear ?? $now->format('Y')),
                ],
                'statistics',
                $locale,
            ),
            StatisticsFilterPeriod::Month => $this->monthLabel(
                $filter->referenceYear ?? (int) $now->format('Y'),
                $filter->referenceMonth ?? (int) $now->format('n'),
                $locale,
            ),
        };
    }

    private function myHospitalsLabel(?User $user, ?string $locale): string
    {
        if ($user instanceof User && 0 === $this->hospitalAccess->countAccessibleHospitals($user)) {
            return $this->translator->trans(
                'stats.analysis_explorer.summary.scope.all_hospitals',
                [],
                'statistics',
                $locale,
            );
        }

        return $this->hospitalScopeLabelResolver->groupLabel($user, $locale);
    }

    private function hospitalName(?int $hospitalId): ?string
    {
        if (null === $hospitalId || $hospitalId <= 0) {
            return null;
        }

        $name = $this->hospitalLookup->findById($hospitalId)?->getName();

        return null !== $name && '' !== $name ? $name : null;
    }

    private function stateName(?int $stateId): ?string
    {
        if (null === $stateId || $stateId <= 0) {
            return null;
        }

        $name = $this->stateLookup->findById($stateId)?->getName();

        return null !== $name && '' !== $name ? $name : null;
    }

    private function dispatchAreaName(?int $dispatchAreaId): ?string
    {
        if (null === $dispatchAreaId || $dispatchAreaId <= 0) {
            return null;
        }

        $name = $this->dispatchAreaLookup->findById($dispatchAreaId)?->getName();

        return null !== $name && '' !== $name ? $name : null;
    }

    private function monthLabel(int $year, int $month, ?string $locale): string
    {
        $month = max(1, min(12, $month));
        $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($midMonth, 'LLLL yyyy', $locale ?? 'en');
        if (false !== $formatted && '' !== $formatted) {
            return $formatted;
        }

        return sprintf('%04d-%02d', $year, $month);
    }
}
