<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisSummaryPart;
use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisSummaryViewModel;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerAnalysisSummaryFactory
{
    private const int MAX_INLINE_FILTERS = 2;

    private const string MARKER_PREFIX = "\u{E000}";

    private const string MARKER_SUFFIX = "\u{E001}";

    public function __construct(
        private TranslatorInterface $translator,
        private ExplorerAnalysisSummaryLabelResolverInterface $labelResolver,
        private ExplorerAnalysisSummaryFilterLabelsInterface $filterBadgePresenter,
    ) {
    }

    public function create(AnalysisViewConfig $config, ?User $user = null, ?string $locale = null): AnalysisSummaryViewModel
    {
        $scope = $this->labelResolver->scopeLabel($config->statisticsFilter, $user, $locale);
        $period = $this->labelResolver->periodLabel($config->statisticsFilter, $locale);
        $subject = $this->metricLabel($config->visualMetricKey, $locale);

        $filterPresentation = $this->presentFilters($config, $locale);

        $parts = match (true) {
            $config->visualMetricKey->isDistributionProfile() => $this->distributionProfileParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
            AnalysisDataSourceKey::Hospitals === $config->dataSourceKey => $this->hospitalsParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
            $this->isTemporalPrimary($config) && !$config->hasColumnAxis() => $this->temporalParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
            $this->isTemporalPrimary($config) && $config->hasColumnAxis() => $this->overTimeParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
            $config->hasColumnAxis() => $this->matrixParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
            default => $this->breakdownParts(
                $config,
                $subject,
                $scope,
                $period,
                $locale,
            ),
        };

        $parts = $this->appendFilterParts($parts, $filterPresentation['inlineParts'], $locale);

        return new AnalysisSummaryViewModel(
            parts: $this->normalizeParts($parts),
            abbreviatedFilterLabels: $filterPresentation['abbreviatedLabels'],
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function temporalParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        return $this->renderTemplate(
            'stats.analysis_explorer.summary.temporal',
            [
                'subject' => $subject,
                'scope' => $scope,
                'period' => $period,
                'grouping' => $this->grainAdverb($config->rowAxis->resolvedGrain(), $locale),
            ],
            $locale,
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function breakdownParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        $dimension = $this->dimensionLabel($config->rowAxis->dimensionKey, $locale);
        $limit = $config->presentation->chartRowLimit;

        if (ExplorerChartRowLimit::All !== $limit) {
            return $this->renderTemplate(
                'stats.analysis_explorer.summary.toplist',
                [
                    'limit' => (string) $limit->cap(),
                    'dimension' => $dimension,
                    'scope' => $scope,
                    'period' => $period,
                    'metric' => $subject,
                ],
                $locale,
            );
        }

        return $this->renderTemplate(
            'stats.analysis_explorer.summary.breakdown',
            [
                'subject' => $subject,
                'scope' => $scope,
                'period' => $period,
                'dimension' => $dimension,
            ],
            $locale,
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function overTimeParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        $columnAxis = $config->columnAxis;
        \assert($columnAxis instanceof AnalysisAxisRef);

        return $this->renderTemplate(
            'stats.analysis_explorer.summary.over_time',
            [
                'subject' => $subject,
                'scope' => $scope,
                'period' => $period,
                'grouping' => $this->grainAdverb($config->rowAxis->resolvedGrain(), $locale),
                'dimension' => $this->dimensionLabel($columnAxis->dimensionKey, $locale),
            ],
            $locale,
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function matrixParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        $columnAxis = $config->columnAxis;
        \assert($columnAxis instanceof AnalysisAxisRef);

        $rows = $this->axisNarrativeLabel($config->rowAxis, $locale);
        $columns = $this->axisNarrativeLabel($columnAxis, $locale);

        return $this->renderTemplate(
            'stats.analysis_explorer.summary.matrix',
            [
                'subject' => $subject,
                'rows' => $rows,
                'columns' => $columns,
                'scope' => $scope,
                'period' => $period,
            ],
            $locale,
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function distributionProfileParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        return $this->renderTemplate(
            'stats.analysis_explorer.summary.distribution_profile',
            [
                'subject' => $subject,
                'scope' => $scope,
                'period' => $period,
                'dimension' => $this->dimensionLabel($config->rowAxis->dimensionKey, $locale),
            ],
            $locale,
        );
    }

    /**
     * @return list<AnalysisSummaryPart>
     */
    private function hospitalsParts(
        AnalysisViewConfig $config,
        string $subject,
        string $scope,
        string $period,
        ?string $locale,
    ): array {
        $population = $this->translator->trans(
            $config->hospitalPopulationMode->labelTranslationKey(),
            [],
            'statistics',
            $locale,
        );

        $includeGeographicScope = StatisticsFilterScope::Public !== $config->statisticsFilter->scope;

        if (ExplorerHospitalPopulationMode::Compare === $config->hospitalPopulationMode) {
            $params = [
                'subject' => $subject,
                'period' => $period,
                'dimension' => $this->dimensionLabel($config->rowAxis->dimensionKey, $locale),
            ];
            if ($includeGeographicScope) {
                $params['scope'] = $scope;

                return $this->renderTemplate(
                    'stats.analysis_explorer.summary.hospitals_compare_scoped',
                    $params,
                    $locale,
                );
            }

            return $this->renderTemplate(
                'stats.analysis_explorer.summary.hospitals_compare',
                $params,
                $locale,
            );
        }

        if ($config->hasColumnAxis()) {
            $columnAxis = $config->columnAxis;
            \assert($columnAxis instanceof AnalysisAxisRef);

            $params = [
                'subject' => $subject,
                'population' => $population,
                'rows' => $this->axisNarrativeLabel($config->rowAxis, $locale),
                'columns' => $this->axisNarrativeLabel($columnAxis, $locale),
                'period' => $period,
            ];
            if ($includeGeographicScope) {
                $params['scope'] = $scope;

                return $this->renderTemplate(
                    'stats.analysis_explorer.summary.hospitals_matrix_scoped',
                    $params,
                    $locale,
                );
            }

            return $this->renderTemplate(
                'stats.analysis_explorer.summary.hospitals_matrix',
                $params,
                $locale,
            );
        }

        $params = [
            'subject' => $subject,
            'population' => $population,
            'period' => $period,
            'dimension' => $this->dimensionLabel($config->rowAxis->dimensionKey, $locale),
        ];
        if ($includeGeographicScope) {
            $params['scope'] = $scope;

            return $this->renderTemplate(
                'stats.analysis_explorer.summary.hospitals_scoped',
                $params,
                $locale,
            );
        }

        return $this->renderTemplate(
            'stats.analysis_explorer.summary.hospitals',
            $params,
            $locale,
        );
    }

    /**
     * @param list<AnalysisSummaryPart> $parts
     * @param list<AnalysisSummaryPart> $inlineFilterParts
     *
     * @return list<AnalysisSummaryPart>
     */
    private function appendFilterParts(array $parts, array $inlineFilterParts, ?string $locale): array
    {
        if ([] === $inlineFilterParts) {
            return $parts;
        }

        $prefix = $this->translator->trans(
            'stats.analysis_explorer.summary.filtered_prefix',
            [],
            'statistics',
            $locale,
        );

        return [
            ...$parts,
            new AnalysisSummaryPart($prefix),
            new AnalysisSummaryPart(' '),
            ...$inlineFilterParts,
            new AnalysisSummaryPart('.'),
        ];
    }

    /**
     * @return array{inlineParts: list<AnalysisSummaryPart>, abbreviatedLabels: list<string>}
     */
    private function presentFilters(AnalysisViewConfig $config, ?string $locale): array
    {
        $badges = $this->filterBadgePresenter->present($config, $locale);
        if ([] === $badges) {
            return ['inlineParts' => [], 'abbreviatedLabels' => []];
        }

        $entries = [];
        foreach ($badges as $badge) {
            $entries[] = trim($badge['label']).': '.trim($badge['value']);
        }

        if (\count($entries) <= self::MAX_INLINE_FILTERS) {
            return [
                'inlineParts' => $this->joinFilterEntries($entries, $locale),
                'abbreviatedLabels' => [],
            ];
        }

        $inline = \array_slice($entries, 0, self::MAX_INLINE_FILTERS);
        $remaining = \array_slice($entries, self::MAX_INLINE_FILTERS);
        $moreCount = \count($remaining);

        $parts = $this->joinFilterEntries($inline, $locale);
        $parts[] = new AnalysisSummaryPart(
            $this->translator->trans(
                'stats.analysis_explorer.summary.filters_more_separator',
                [],
                'statistics',
                $locale,
            ),
        );
        $parts[] = new AnalysisSummaryPart(
            $this->translator->trans(
                'stats.analysis_explorer.summary.more_filters',
                ['count' => $moreCount],
                'statistics',
                $locale,
            ),
            true,
        );

        return [
            'inlineParts' => $parts,
            'abbreviatedLabels' => $entries,
        ];
    }

    /**
     * @param list<string> $entries
     *
     * @return list<AnalysisSummaryPart>
     */
    private function joinFilterEntries(array $entries, ?string $locale): array
    {
        $parts = [];
        $and = $this->translator->trans(
            'stats.analysis_explorer.summary.filters_and',
            [],
            'statistics',
            $locale,
        );
        $comma = $this->translator->trans(
            'stats.analysis_explorer.summary.filters_comma',
            [],
            'statistics',
            $locale,
        );

        foreach ($entries as $index => $entry) {
            if ($index > 0) {
                $separator = $index === \count($entries) - 1 ? $and : $comma;
                $parts[] = new AnalysisSummaryPart($separator);
            }
            $parts[] = new AnalysisSummaryPart($entry, true);
        }

        return $parts;
    }

    /**
     * @param array<string, string> $emphasizedParams
     *
     * @return list<AnalysisSummaryPart>
     */
    private function renderTemplate(string $translationKey, array $emphasizedParams, ?string $locale): array
    {
        $markersByName = [];
        $transParams = [];
        foreach ($emphasizedParams as $name => $value) {
            $marker = self::MARKER_PREFIX.$name.self::MARKER_SUFFIX;
            $markersByName[$marker] = $value;
            $transParams[$name] = $marker;
        }

        $translated = $this->translator->trans($translationKey, $transParams, 'statistics', $locale);

        return $this->splitMarkedText($translated, $markersByName);
    }

    /**
     * @param array<string, string> $markersByName
     *
     * @return list<AnalysisSummaryPart>
     */
    private function splitMarkedText(string $translated, array $markersByName): array
    {
        if ([] === $markersByName) {
            return [new AnalysisSummaryPart($translated)];
        }

        $pattern = '/('.implode('|', array_map(
            static fn (string $marker): string => preg_quote($marker, '/'),
            array_keys($markersByName),
        )).')/u';

        $chunks = preg_split($pattern, $translated, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        if (false === $chunks) {
            return [new AnalysisSummaryPart($translated)];
        }

        $parts = [];
        foreach ($chunks as $chunk) {
            if (isset($markersByName[$chunk])) {
                $parts[] = new AnalysisSummaryPart($markersByName[$chunk], true);
                continue;
            }
            $parts[] = new AnalysisSummaryPart($chunk);
        }

        return $parts;
    }

    /**
     * @param list<AnalysisSummaryPart> $parts
     *
     * @return list<AnalysisSummaryPart>
     */
    private function normalizeParts(array $parts): array
    {
        $normalized = [];
        foreach ($parts as $part) {
            $text = $part->emphasize ? trim($part->text) : $part->text;
            if ('' === $text) {
                continue;
            }
            $normalized[] = new AnalysisSummaryPart($text, $part->emphasize);
        }

        return $normalized;
    }

    private function isTemporalPrimary(AnalysisViewConfig $config): bool
    {
        return $config->rowAxis->dimensionKey->isTemporalPrimary();
    }

    private function metricLabel(AnalysisMetricKey $metricKey, ?string $locale): string
    {
        if ($metricKey->isDistributionProfile()) {
            return $this->translator->trans(
                'stats.analysis_explorer.metric_profile.'.$metricKey->value,
                [],
                'statistics',
                $locale,
            );
        }

        return $this->translator->trans(
            'stats.analysis_explorer.metric.'.$metricKey->value,
            [],
            'statistics',
            $locale,
        );
    }

    private function dimensionLabel(AnalysisDimensionKey $dimensionKey, ?string $locale): string
    {
        return $this->translator->trans(
            'stats.analysis_explorer.dimension.'.$dimensionKey->value,
            [],
            'statistics',
            $locale,
        );
    }

    private function axisNarrativeLabel(AnalysisAxisRef $axis, ?string $locale): string
    {
        if ($axis->dimensionKey->isTemporalPrimary()) {
            return $this->grainNoun($axis->resolvedGrain(), $locale);
        }

        $dimension = $this->dimensionLabel($axis->dimensionKey, $locale);
        $grain = $axis->resolvedGrain();
        if ($grain->isTemporal()) {
            return $this->translator->trans(
                'stats.analysis_explorer.summary.dimension_by_grain',
                [
                    'dimension' => $dimension,
                    'grain' => $this->grainNoun($grain, $locale),
                ],
                'statistics',
                $locale,
            );
        }

        return $dimension;
    }

    private function grainAdverb(AnalysisDimensionGrain $grain, ?string $locale): string
    {
        return $this->translator->trans(
            'stats.analysis_explorer.summary.grain.'.$grain->value,
            [],
            'statistics',
            $locale,
        );
    }

    private function grainNoun(AnalysisDimensionGrain $grain, ?string $locale): string
    {
        return match ($grain) {
            AnalysisDimensionGrain::Month => $this->translator->trans(
                'stats.analysis_explorer.dimension.month',
                [],
                'statistics',
                $locale,
            ),
            AnalysisDimensionGrain::Year => $this->translator->trans(
                'stats.analysis_explorer.dimension.year',
                [],
                'statistics',
                $locale,
            ),
            AnalysisDimensionGrain::Quarter => $this->translator->trans(
                'stats.analysis_explorer.dimension.quarter',
                [],
                'statistics',
                $locale,
            ),
            AnalysisDimensionGrain::Week => $this->translator->trans(
                'stats.analysis_explorer.dimension.week',
                [],
                'statistics',
                $locale,
            ),
            AnalysisDimensionGrain::Total => $this->translator->trans(
                'stats.analysis_explorer.grain.total',
                [],
                'statistics',
                $locale,
            ),
        };
    }
}
