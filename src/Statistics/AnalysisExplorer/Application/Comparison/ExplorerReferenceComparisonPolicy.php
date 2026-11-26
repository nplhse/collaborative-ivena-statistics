<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Comparison;

use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\ScopePrivacyPolicy;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Domain\ReferenceComparison;
use App\User\Domain\Entity\User;

/**
 * Decides which reference comparison (#1346) is effective for the current viewer. It is the one check for the
 * live explorer, saved and public views and the CSV export. The comparison is effective only if all of these hold:
 *
 * - allocations data source, no manual column axis, a scope other than a single hospital;
 * - 1–5 distinct references;
 * - the viewer holds Benchmarking on every reference (administrators on every hospital);
 * - every reference belongs to the scope, and the scope minus the references still has
 *   ScopePrivacyPolicy::MIN_HOSPITALS hospitals.
 *
 * Otherwise the whole comparison is dropped from the effective configuration: the references and the share within
 * series, so no reference id or name reaches the page or the download. There is no partial comparison. The rest is
 * normalized again, so presentation settings that only fit the comparison (a grouped bar chart) fall back.
 */
final readonly class ExplorerReferenceComparisonPolicy
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsScopeResolver $statisticsScopeResolver,
        private ScopeHospitalIdsReaderInterface $scopeHospitalIds,
        private GenericAnalysisEntityLabelResolverInterface $entityLabelResolver,
        private AnalysisViewConfigNormalizer $configNormalizer,
    ) {
    }

    /**
     * Hospitals the viewer may pick as references in this scope (Benchmarking, inside the scope), by name, and how many
     * may be picked so that at least ScopePrivacyPolicy::MIN_HOSPITALS others remain (at most 5). Empty when the
     * comparison is not available for this scope or viewer.
     */
    public function candidates(AnalysisDataSourceKey $dataSourceKey, StatisticsFilter $filter, ?User $viewer): ReferenceCandidates
    {
        if (!$viewer instanceof User
            || AnalysisDataSourceKey::Allocations !== $dataSourceKey
            || StatisticsFilterScope::Hospital === $filter->scope) {
            return new ReferenceCandidates();
        }

        $scopeHospitals = $this->scopeHospitals($filter, $viewer);
        $maxReferences = min(ReferenceComparison::MAX_REFERENCES, \count($scopeHospitals) - ScopePrivacyPolicy::MIN_HOSPITALS);
        if ($maxReferences < 1) {
            return new ReferenceCandidates();
        }

        $ids = array_values(array_intersect($this->hospitalAccess->benchmarkingHospitalIds($viewer), $scopeHospitals));
        $names = $this->entityLabelResolver->resolve('hospital', $ids);
        asort($names, \SORT_NATURAL | \SORT_FLAG_CASE);

        return new ReferenceCandidates($names, $maxReferences);
    }

    public function effective(AnalysisViewConfig $config, ?User $viewer): ReferenceComparisonDecision
    {
        if (!$config->hasReferenceComparison()) {
            return new ReferenceComparisonDecision($config);
        }

        if ($this->isAvailable($config, $viewer)) {
            return new ReferenceComparisonDecision($config);
        }

        return new ReferenceComparisonDecision($this->withoutComparison($config), dropped: true);
    }

    private function isAvailable(AnalysisViewConfig $config, ?User $viewer): bool
    {
        if (!$viewer instanceof User
            || AnalysisDataSourceKey::Allocations !== $config->dataSourceKey
            || $config->hasColumnAxis()
            || StatisticsFilterScope::Hospital === $config->statisticsFilter->scope) {
            return false;
        }

        $references = array_values(array_unique($config->referenceHospitalIds));
        if (\count($references) > ReferenceComparison::MAX_REFERENCES) {
            return false;
        }

        $benchmarking = $this->hospitalAccess->benchmarkingHospitalIds($viewer);
        if ([] !== array_diff($references, $benchmarking)) {
            return false;
        }

        $scopeHospitals = $this->scopeHospitals($config->statisticsFilter, $viewer);
        if ([] !== array_diff($references, $scopeHospitals)) {
            return false;
        }

        return \count($scopeHospitals) - \count($references) >= ScopePrivacyPolicy::MIN_HOSPITALS;
    }

    /**
     * @return list<int>
     */
    private function scopeHospitals(StatisticsFilter $filter, User $viewer): array
    {
        return $this->scopeHospitalIds->hospitalIds(
            $this->statisticsScopeResolver->resolveCriteria($this->statisticsContextFactory->create($viewer, $filter)),
        );
    }

    private function withoutComparison(AnalysisViewConfig $config): AnalysisViewConfig
    {
        $metricKeys = array_values(array_filter(
            $config->metricKeys,
            static fn (AnalysisMetricKey $key): bool => AnalysisMetricKey::PercentOfSeries !== $key,
        ));

        return $this->configNormalizer->normalize($config
            ->withReferenceHospitalIds([])
            ->withMetrics([] === $metricKeys ? [$config->visualMetricKey] : $metricKeys, $config->visualMetricKey));
    }
}
