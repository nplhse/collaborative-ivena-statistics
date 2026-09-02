<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisSummaryLabelResolverInterface;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileView;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransportTimeProfileBuilder
{
    public function __construct(
        private StatisticsScopeResolver $scopeResolver,
        private TransportTimeProfileSliceQueryInterface $sliceQuery,
        private TransportTimeProfileAssembler $assembler,
        private TransportTimeProfileInsightGenerator $insightGenerator,
        private GenericAnalysisEntityLabelResolverInterface $entityLabelResolver,
        private ExplorerAnalysisSummaryLabelResolverInterface $summaryLabelResolver,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(StatisticsContext $context, string $locale): TransportTimeProfileView
    {
        $scopeCriteria = $this->scopeResolver->resolveCriteria($context);
        $periodBounds = StatisticsPeriodResolver::resolve($context->filter);
        $slice = $this->sliceQuery->fetch($scopeCriteria, $periodBounds);

        $bucketLabels = $this->bucketLabels($locale);
        $scopeLabel = $this->summaryLabelResolver->scopeLabel($context->filter, $context->user, $locale);
        $periodLabel = $this->summaryLabelResolver->periodLabel($context->filter, $locale);
        $allocationCount = $slice->allocationTotal();
        $knownTotal = $slice->knownTotal();
        $hasData = $allocationCount > 0;

        $contextLine = $this->translator->trans(
            'stats.reports.transport_time_profile.context',
            [
                'scope' => $scopeLabel,
                'period' => $periodLabel,
                'count' => $allocationCount,
            ],
            'statistics',
            $locale,
        );

        $filterParams = $this->filterQueryParams($context->filter);
        $departmentIds = $this->collectIds($slice->departmentsByBucket);
        $specialityIds = $this->collectIds($slice->specialitiesByBucket);
        $indicationIds = $this->collectIds($slice->indicationsByBucket);

        $departmentNames = $this->entityLabelResolver->resolve('department', $departmentIds);
        $specialityNames = $this->entityLabelResolver->resolve('speciality', $specialityIds);
        $indicationNames = $this->entityLabelResolver->resolve('indication', $indicationIds);

        $indicationUrls = [];
        foreach ($indicationIds as $id) {
            $indicationUrls[$id] = $this->urlGenerator->generate(
                'app_stats_indication_dashboard',
                ['indicationId' => $id] + $filterParams,
            );
        }

        $departmentsUrl = $this->urlGenerator->generate(
            'app_stats_top_lists_show',
            ['report' => 'top_departments'] + $filterParams,
        );
        $specialitiesUrl = $this->urlGenerator->generate(
            'app_stats_top_lists_show',
            ['report' => 'top_specialities'] + $filterParams,
        );
        $explorerUrl = $this->urlGenerator->generate(
            'app_stats_analysis_explorer_view',
            ['view' => 'transport-time-bucket-distribution'] + $filterParams,
        );

        $matrix = $hasData && $knownTotal > 0
            ? $this->assembler->matrix(
                $slice,
                $locale,
                $departmentNames,
                $specialityNames,
                $indicationNames,
                $indicationUrls,
                $departmentsUrl,
                $specialitiesUrl,
            )
            : [];
        $chartSpecs = $hasData && $knownTotal > 0
            ? $this->assembler->chartSpecs($slice, $bucketLabels, $locale)
            : [];
        $volume = $this->assembler->volumeByBucket($slice);
        $insights = $hasData && $knownTotal > 0
            ? $this->insightGenerator->generate($volume, $matrix, $bucketLabels, $knownTotal, $locale)
            : [];
        [$matrixSections, $rankedSections] = $this->partitionMatrix($matrix);

        return new TransportTimeProfileView(
            hasData: $hasData,
            allocationCount: $allocationCount,
            knownTransportCount: $knownTotal,
            unknownTransportCount: $slice->unknownCount,
            contextLine: $contextLine,
            scopeLabel: $scopeLabel,
            periodLabel: $periodLabel,
            importCreateUrl: $this->urlGenerator->generate('app_import_new'),
            dashboardUrl: $this->urlGenerator->generate('app_stats_dashboard', $filterParams),
            explorerTransportTimeUrl: $explorerUrl,
            bucketLabels: $bucketLabels,
            chartSpecs: $chartSpecs,
            insights: $insights,
            matrixSections: $matrixSections,
            rankedSections: $rankedSections,
            drawerFilterActive: false,
        );
    }

    /**
     * @param list<TransportTimeProfileMatrixSection> $sections
     *
     * @return array{0: list<TransportTimeProfileMatrixSection>, 1: list<TransportTimeProfileMatrixSection>}
     */
    private function partitionMatrix(array $sections): array
    {
        $composition = [];
        $ranked = [];
        foreach ($sections as $section) {
            if (\in_array($section->key, ['departments', 'specialities', 'indications'], true)) {
                $ranked[] = $section;
            } else {
                $composition[] = $section;
            }
        }

        return [$composition, $ranked];
    }

    /**
     * @return array<string, string>
     */
    private function bucketLabels(string $locale): array
    {
        $labels = [];
        foreach (StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS as $key) {
            $labels[$key] = $this->translator->trans(
                StatisticsTransportTimeBucketSql::translationKey($key),
                [],
                'statistics',
                $locale,
            );
        }

        return $labels;
    }

    /**
     * @param array<string, list<array{id: int, count: int}>> $ranked
     *
     * @return list<int>
     */
    private function collectIds(array $ranked): array
    {
        $ids = [];
        foreach ($ranked as $items) {
            foreach ($items as $item) {
                $ids[] = $item['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, scalar>
     */
    private function filterQueryParams(StatisticsFilter $filter): array
    {
        return array_filter(
            [
                StatisticsQueryKeys::SCOPE => $filter->scope->value,
                StatisticsQueryKeys::HOSPITAL => $filter->hospitalId,
                StatisticsQueryKeys::STATE => $filter->stateId,
                StatisticsQueryKeys::DISPATCH_AREA => $filter->dispatchAreaId,
                StatisticsQueryKeys::COHORT => $filter->cohortType?->value(),
                StatisticsQueryKeys::PERIOD => $filter->period->value,
                StatisticsQueryKeys::YEAR => $filter->referenceYear,
                StatisticsQueryKeys::MONTH => $filter->referenceMonth,
                StatisticsQueryKeys::QUARTER => $filter->referenceQuarter,
            ],
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );
    }
}
