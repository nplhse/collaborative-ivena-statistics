<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisFamily;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-model summary of the applied analysis selection for Explorer chrome.
 *
 * @phpstan-type SummaryChip array{key: string, label: string, value: string, opensEdit: bool}
 */
final readonly class ExplorerSelectionSummaryPresenter
{
    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerFilterBadgePresenter $filterBadgePresenter,
        private ExplorerMetricProfileRegistry $metricProfileRegistry,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private HospitalCohortLabelResolver $hospitalCohortLabelResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     family: ?SummaryChip,
     *     structure: list<SummaryChip>,
     *     scope: SummaryChip,
     *     period: SummaryChip,
     *     filters: list<SummaryChip>
     * }
     */
    public function present(AnalysisViewConfig $config, ?string $analysisFamily, ?User $user, string $locale = 'en'): array
    {
        $structure = [
            $this->chip('row', 'stats.analysis_explorer.selection.row', $this->axisLabel($config->rowAxis), true),
        ];
        if ($config->columnAxis instanceof AnalysisAxisRef) {
            $structure[] = $this->chip(
                'column',
                'stats.analysis_explorer.selection.column',
                $this->axisLabel($config->columnAxis),
                true,
            );
        }
        $structure[] = $this->chip(
            'metric',
            'stats.analysis_explorer.selection.metric',
            $this->metricLabel($config->visualMetricKey),
            true,
        );

        $filters = [];
        foreach ($this->filterBadgePresenter->present($config) as $badge) {
            $filters[] = [
                'key' => 'filter',
                'label' => $badge['label'],
                'value' => $badge['value'],
                'opensEdit' => true,
            ];
        }

        return [
            'family' => $this->familyChip($analysisFamily),
            'structure' => $structure,
            'scope' => $this->chip(
                'scope',
                'stats.analysis_explorer.selection.scope',
                $this->scopeLabel($config->statisticsFilter, $user, $locale),
                true,
            ),
            'period' => $this->chip(
                'period',
                'stats.analysis_explorer.selection.period',
                $this->periodLabel($config->statisticsFilter, $locale),
                true,
            ),
            'filters' => $filters,
        ];
    }

    /**
     * @return SummaryChip|null
     */
    private function familyChip(?string $analysisFamily): ?array
    {
        if (null === $analysisFamily || '' === $analysisFamily) {
            return null;
        }

        if (null === AnalysisFamily::tryFrom($analysisFamily)) {
            return null;
        }

        return $this->chip(
            'family',
            'stats.analysis_explorer.selection.family',
            $this->translator->trans('stats.analysis_explorer.family.'.$analysisFamily, [], 'statistics'),
            false,
        );
    }

    /**
     * @return SummaryChip
     */
    private function chip(string $key, string $labelKey, string $value, bool $opensEdit): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans($labelKey, [], 'statistics'),
            'value' => $value,
            'opensEdit' => $opensEdit,
        ];
    }

    private function metricLabel(AnalysisMetricKey $metricKey): string
    {
        $profile = $this->metricProfileRegistry->profileFor($metricKey);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey, [], 'statistics');
        }

        return $this->translator->trans('stats.analysis_explorer.metric.'.$metricKey->value, [], 'statistics');
    }

    private function axisLabel(AnalysisAxisRef $axis): string
    {
        $dimensionLabel = $axis->dimensionKey->isTemporalPrimary()
            ? $this->translator->trans('stats.analysis_explorer.dimension.time', [], 'statistics')
            : $this->translator->trans('stats.analysis_explorer.dimension.'.$axis->dimensionKey->value, [], 'statistics');

        $grain = $axis->resolvedGrain();
        if (AnalysisDimensionKey::Time !== $axis->dimensionKey && AnalysisDimensionGrain::Total === $grain) {
            return $dimensionLabel;
        }

        return $this->translator->trans('stats.analysis_explorer.edit.structure_axis_with_grain', [
            'dimension' => $dimensionLabel,
            'grain' => $this->grainLabel($grain),
        ], 'statistics');
    }

    private function grainLabel(AnalysisDimensionGrain $grain): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans('stats.analysis_explorer.dimension.month', [], 'statistics'),
            AnalysisDimensionGrain::Year => $this->translator->trans('stats.analysis_explorer.dimension.year', [], 'statistics'),
            AnalysisDimensionGrain::Quarter => $this->translator->trans('stats.analysis_explorer.dimension.quarter', [], 'statistics'),
            AnalysisDimensionGrain::Week => $this->translator->trans('stats.analysis_explorer.dimension.week', [], 'statistics'),
            AnalysisDimensionGrain::Total => $this->translator->trans('stats.analysis_explorer.grain.total', [], 'statistics'),
        };
    }

    private function scopeLabel(StatisticsFilter $filter, ?User $user, string $locale): string
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public', [], 'statistics', $locale),
            StatisticsFilterScope::MyHospitals => $this->hospitalScopeLabelResolver->groupLabel($user, $locale),
            StatisticsFilterScope::Hospital => $this->hospitalName($filter->hospitalId)
                ?? $this->translator->trans('stats.filter.hospital.choose', [], 'statistics', $locale),
            StatisticsFilterScope::HospitalCohort => $filter->cohortType instanceof HospitalCohortKey
                ? $this->hospitalCohortLabelResolver->label($filter->cohortType, $locale)
                : $this->translator->trans('stats.filter.scope.hospital_cohort', [], 'statistics', $locale),
            StatisticsFilterScope::State => $this->translator->trans('stats.filter.scope.state', [], 'statistics', $locale),
            StatisticsFilterScope::DispatchArea => $this->translator->trans('stats.filter.scope.dispatch_area', [], 'statistics', $locale),
        };
    }

    private function hospitalName(?int $hospitalId): ?string
    {
        if (null === $hospitalId || $hospitalId <= 0) {
            return null;
        }

        $hospital = $this->entityManager->find(Hospital::class, $hospitalId);

        return $hospital instanceof Hospital ? $hospital->getName() : null;
    }

    private function periodLabel(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all', [], 'statistics', $locale),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time', [], 'statistics', $locale),
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
            StatisticsFilterPeriod::Month => $this->monthPeriodLabel($filter, $locale),
        };
    }

    private function monthPeriodLabel(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $month = max(1, min(12, $filter->referenceMonth ?? (int) $now->format('n')));
        $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($midMonth, 'LLLL yyyy', $locale);

        return false !== $formatted && '' !== $formatted
            ? $formatted
            : sprintf('%04d-%02d', $year, $month);
    }
}
