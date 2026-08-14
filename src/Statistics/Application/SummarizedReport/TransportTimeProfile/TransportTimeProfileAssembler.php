<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileCell;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixRow;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileSliceData;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransportTimeProfileAssembler
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<int, string> $departmentNames
     * @param array<int, string> $specialityNames
     * @param array<int, string> $indicationNames
     * @param array<int, string> $indicationUrls
     *
     * @return list<TransportTimeProfileMatrixSection>
     */
    public function matrix(
        TransportTimeProfileSliceData $slice,
        string $locale,
        array $departmentNames,
        array $specialityNames,
        array $indicationNames,
        array $indicationUrls,
        string $departmentsUrl,
        string $specialitiesUrl,
    ): array {
        $buckets = StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS;
        $volume = $this->volumeByBucket($slice);
        $knownTotal = array_sum($volume);

        return [
            $this->volumeSection($volume, $knownTotal, $buckets, $locale),
            $this->compositionSection(
                'gender',
                'stats.reports.transport_time_profile.section.gender',
                $this->genderRows($slice, $volume, $knownTotal, $buckets, $locale),
            ),
            $this->compositionSection(
                'urgency',
                'stats.reports.transport_time_profile.section.urgency',
                $this->urgencyRows($slice, $volume, $knownTotal, $buckets, $locale),
            ),
            $this->rankedSection(
                'departments',
                'stats.reports.transport_time_profile.section.departments',
                $slice->departmentsByBucket,
                $departmentNames,
                $volume,
                $buckets,
                $departmentsUrl,
                [],
            ),
            $this->rankedSection(
                'specialities',
                'stats.reports.transport_time_profile.section.specialities',
                $slice->specialitiesByBucket,
                $specialityNames,
                $volume,
                $buckets,
                $specialitiesUrl,
                [],
            ),
            $this->rankedSection(
                'indications',
                'stats.reports.transport_time_profile.section.indications',
                $slice->indicationsByBucket,
                $indicationNames,
                $volume,
                $buckets,
                null,
                $indicationUrls,
            ),
            $this->compositionSection(
                'physician',
                'stats.reports.transport_time_profile.section.physician',
                [$this->rateRow(
                    'with_physician',
                    $this->translator->trans('stats.reports.transport_time_profile.metric.with_physician', [], 'statistics', $locale),
                    $this->trueCounts($slice->physicianByBucket),
                    $volume,
                    $knownTotal,
                    $buckets,
                )],
            ),
            $this->compositionSection(
                'resources',
                'stats.reports.transport_time_profile.section.resources',
                [
                    $this->rateRow(
                        'requires_resus',
                        $this->translator->trans('stats.reports.transport_time_profile.metric.requires_resus', [], 'statistics', $locale),
                        $this->trueCounts($slice->resusByBucket),
                        $volume,
                        $knownTotal,
                        $buckets,
                    ),
                    $this->rateRow(
                        'requires_cathlab',
                        $this->translator->trans('stats.reports.transport_time_profile.metric.requires_cathlab', [], 'statistics', $locale),
                        $this->trueCounts($slice->cathlabByBucket),
                        $volume,
                        $knownTotal,
                        $buckets,
                    ),
                ],
            ),
            $this->compositionSection(
                'transport_mode',
                'stats.reports.transport_time_profile.section.transport_mode',
                $this->transportRows($slice, $volume, $knownTotal, $buckets, $locale),
            ),
        ];
    }

    /**
     * @param array<string, string> $bucketLabels
     *
     * @return array<string, array<string, mixed>>
     */
    public function chartSpecs(
        TransportTimeProfileSliceData $slice,
        array $bucketLabels,
        string $locale,
    ): array {
        $buckets = StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS;
        $labels = [];
        $counts = [];
        $volume = $this->volumeByBucket($slice);
        foreach ($buckets as $bucket) {
            $labels[] = $bucketLabels[$bucket] ?? $bucket;
            $counts[] = $volume[$bucket];
        }

        $casesLabel = $this->translator->trans(
            'stats.reports.transport_time_profile.chart.cases',
            [],
            'statistics',
            $locale,
        );
        $axisLabel = $this->translator->trans(
            'stats.reports.transport_time_profile.chart.axis',
            [],
            'statistics',
            $locale,
        );

        return [
            'cases' => [
                'chartType' => 'bar',
                'labels' => $labels,
                'counts' => $counts,
                'valueLabel' => $casesLabel,
                'xAxisLabel' => $axisLabel,
                'yAxisLabel' => $casesLabel,
            ],
            'gender' => $this->percentStackedSpec(
                $labels,
                $this->genderSeries($slice, $volume, $buckets, $locale),
                $axisLabel,
            ),
            'urgency' => $this->percentStackedSpec(
                $labels,
                $this->urgencySeries($slice, $volume, $buckets, $locale),
                $axisLabel,
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function volumeByBucket(TransportTimeProfileSliceData $slice): array
    {
        $volume = [];
        foreach (StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS as $bucket) {
            $volume[$bucket] = $slice->volumeByBucket[$bucket] ?? 0;
        }

        return $volume;
    }

    /**
     * @param list<TransportTimeProfileMatrixSection> $sections
     *
     * @return array<string, float>
     */
    public function rateSeriesByRow(array $sections, string $sectionKey, string $rowKey): array
    {
        foreach ($sections as $section) {
            if ($section->key !== $sectionKey) {
                continue;
            }
            foreach ($section->rows as $row) {
                if ($row->key !== $rowKey) {
                    continue;
                }
                $percents = [];
                foreach ($row->cells as $bucket => $cell) {
                    $percents[$bucket] = $cell->percent ?? 0.0;
                }

                return $percents;
            }
        }

        return [];
    }

    /**
     * @param list<TransportTimeProfileMatrixSection> $sections
     *
     * @return array<string, list<array{id: string, label: string, percent: float, rank: int}>>
     */
    public function rankedByBucket(array $sections, string $sectionKey): array
    {
        $byBucket = [];
        foreach ($sections as $section) {
            if ($section->key !== $sectionKey) {
                continue;
            }
            foreach ($section->rows as $row) {
                foreach ($row->cells as $bucket => $cell) {
                    $item = $this->rankedItemFromCell($cell);
                    if (null === $item) {
                        continue;
                    }
                    $byBucket[$bucket][] = $item;
                }
            }
        }

        return $byBucket;
    }

    /**
     * @return array{id: string, label: string, percent: float, rank: int}|null
     */
    private function rankedItemFromCell(TransportTimeProfileCell $cell): ?array
    {
        $label = $cell->entityLabel;
        $rank = $cell->rank;
        $percent = $cell->percent;
        if (!\is_string($label) || !\is_int($rank) || !\is_float($percent)) {
            return null;
        }

        return [
            'id' => (string) ($cell->entityId ?? $label),
            'label' => $label,
            'percent' => $percent,
            'rank' => $rank,
        ];
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     */
    private function volumeSection(array $volume, int $knownTotal, array $buckets, string $locale): TransportTimeProfileMatrixSection
    {
        $countCells = [];
        $shareCells = [];
        foreach ($buckets as $bucket) {
            $n = $volume[$bucket];
            $small = TransportTimeProfileMath::isSmallSample($n);
            $countCells[$bucket] = new TransportTimeProfileCell($n, $small, count: $n);
            $shareCells[$bucket] = new TransportTimeProfileCell(
                $n,
                $small,
                count: $n,
                percent: TransportTimeProfileMath::percent($n, $knownTotal),
            );
        }

        return new TransportTimeProfileMatrixSection(
            'volume',
            'stats.reports.transport_time_profile.section.volume',
            [
                new TransportTimeProfileMatrixRow(
                    'cases',
                    $this->translator->trans('stats.reports.transport_time_profile.metric.cases', [], 'statistics', $locale),
                    'count',
                    $countCells,
                ),
                new TransportTimeProfileMatrixRow(
                    'share',
                    $this->translator->trans('stats.reports.transport_time_profile.metric.share', [], 'statistics', $locale),
                    'share',
                    $shareCells,
                ),
            ],
        );
    }

    /**
     * @param list<TransportTimeProfileMatrixRow> $rows
     */
    private function compositionSection(string $key, string $titleKey, array $rows): TransportTimeProfileMatrixSection
    {
        return new TransportTimeProfileMatrixSection($key, $titleKey, $rows);
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<TransportTimeProfileMatrixRow>
     */
    private function genderRows(
        TransportTimeProfileSliceData $slice,
        array $volume,
        int $knownTotal,
        array $buckets,
        string $locale,
    ): array {
        $rows = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $code) {
            $label = $this->translator->trans($code->labelTranslationKey(), [], 'messages', $locale);
            $counts = $this->dimensionCounts($slice->genderByBucket, (string) $code->value);
            $rows[] = $this->rateRow((string) $code->value, $label, $counts, $volume, $knownTotal, $buckets);
        }

        $unknownCounts = $this->dimensionCounts($slice->genderByBucket, 'unknown');
        if (array_sum($unknownCounts) > 0) {
            $rows[] = $this->rateRow(
                'unknown',
                $this->translator->trans('stats.indication.gender.unknown', [], 'statistics', $locale),
                $unknownCounts,
                $volume,
                $knownTotal,
                $buckets,
            );
        }

        return $rows;
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<TransportTimeProfileMatrixRow>
     */
    private function urgencyRows(
        TransportTimeProfileSliceData $slice,
        array $volume,
        int $knownTotal,
        array $buckets,
        string $locale,
    ): array {
        $rows = [];
        foreach (AllocationUrgency::cases() as $urgency) {
            $label = $this->translator->trans($urgency->label(), [], 'messages', $locale);
            $counts = $this->dimensionCounts($slice->urgencyByBucket, (string) $urgency->value);
            $rows[] = $this->rateRow((string) $urgency->value, $label, $counts, $volume, $knownTotal, $buckets);
        }

        return $rows;
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<TransportTimeProfileMatrixRow>
     */
    private function transportRows(
        TransportTimeProfileSliceData $slice,
        array $volume,
        int $knownTotal,
        array $buckets,
        string $locale,
    ): array {
        $rows = [];
        foreach (AllocationStatsTransportTypeProjectionCode::displayOrder() as $code) {
            $label = $this->translator->trans($code->labelTranslationKey(), [], 'messages', $locale);
            $counts = $this->dimensionCounts($slice->transportTypeByBucket, (string) $code->value);
            $rows[] = $this->rateRow((string) $code->value, $label, $counts, $volume, $knownTotal, $buckets);
        }

        return $rows;
    }

    /**
     * @param array<string, int> $countsByBucket
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     */
    private function rateRow(
        string $key,
        string $label,
        array $countsByBucket,
        array $volume,
        int $knownTotal,
        array $buckets,
    ): TransportTimeProfileMatrixRow {
        $overallCount = array_sum($countsByBucket);
        $overallPercent = TransportTimeProfileMath::percent($overallCount, $knownTotal);
        $cells = [];
        foreach ($buckets as $bucket) {
            $n = $volume[$bucket];
            $count = $countsByBucket[$bucket] ?? 0;
            $percent = TransportTimeProfileMath::percent($count, $n);
            $delta = TransportTimeProfileMath::deltaPp($percent, $overallPercent);
            $small = TransportTimeProfileMath::isSmallSample($n);
            $cells[$bucket] = new TransportTimeProfileCell(
                $n,
                $small,
                count: $count,
                percent: $percent,
                deltaPp: $delta,
                heatClass: TransportTimeProfileMath::heatClass($delta, $small),
            );
        }

        return new TransportTimeProfileMatrixRow($key, $label, 'percent', $cells, $overallPercent);
    }

    /**
     * @param array<string, list<array{id: int, count: int}>> $ranked
     * @param array<int, string>                              $names
     * @param array<string, int>                              $volume
     * @param list<string>                                    $buckets
     * @param array<int, string>                              $entityUrls
     */
    private function rankedSection(
        string $key,
        string $titleKey,
        array $ranked,
        array $names,
        array $volume,
        array $buckets,
        ?string $sharedUrl,
        array $entityUrls,
    ): TransportTimeProfileMatrixSection {
        $rows = [];
        for ($rank = 1; $rank <= 5; ++$rank) {
            $cells = [];
            foreach ($buckets as $bucket) {
                $n = $volume[$bucket];
                $small = TransportTimeProfileMath::isSmallSample($n);
                $item = $ranked[$bucket][$rank - 1] ?? null;
                if (null === $item) {
                    $cells[$bucket] = new TransportTimeProfileCell($n, $small);
                    continue;
                }

                $id = $item['id'];
                $percent = TransportTimeProfileMath::percent($item['count'], $n);
                $cells[$bucket] = new TransportTimeProfileCell(
                    $n,
                    $small,
                    count: $item['count'],
                    percent: $percent,
                    rank: $rank,
                    entityLabel: $names[$id] ?? (string) $id,
                    linkUrl: $entityUrls[$id] ?? $sharedUrl,
                    entityId: $id,
                );
            }
            $rows[] = new TransportTimeProfileMatrixRow(
                'rank_'.$rank,
                '#'.$rank,
                'ranked',
                $cells,
            );
        }

        return $this->withColumnRelativeRanks(
            new TransportTimeProfileMatrixSection($key, $titleKey, $rows),
            $ranked,
            $buckets,
        );
    }

    /**
     * Rebuild rank deltas relative to the preceding bucket column.
     *
     * @param array<string, list<array{id: int, count: int}>> $ranked
     * @param list<string>                                    $buckets
     */
    private function withColumnRelativeRanks(
        TransportTimeProfileMatrixSection $section,
        array $ranked,
        array $buckets,
    ): TransportTimeProfileMatrixSection {
        $rankByBucketAndId = [];
        foreach ($buckets as $bucket) {
            foreach ($ranked[$bucket] ?? [] as $index => $item) {
                $rankByBucketAndId[$bucket][$item['id']] = $index + 1;
            }
        }

        $previousRankedBucketByBucket = [];
        $previousRankedBucket = null;
        foreach ($buckets as $bucket) {
            $previousRankedBucketByBucket[$bucket] = $previousRankedBucket;
            if ([] !== ($ranked[$bucket] ?? [])) {
                $previousRankedBucket = $bucket;
            }
        }

        $rows = [];
        foreach ($section->rows as $row) {
            $cells = [];
            foreach ($buckets as $bucket) {
                $cell = $row->cells[$bucket];
                if (null === $cell->entityId) {
                    $cells[$bucket] = $cell;
                    continue;
                }

                $previousBucket = $previousRankedBucketByBucket[$bucket];
                $previousRank = null;
                $enteredTop = false;
                if (null !== $previousBucket) {
                    $previousRank = $rankByBucketAndId[$previousBucket][$cell->entityId] ?? null;
                    $enteredTop = null === $previousRank;
                }

                $cells[$bucket] = new TransportTimeProfileCell(
                    $cell->bucketN,
                    $cell->smallSample,
                    count: $cell->count,
                    percent: $cell->percent,
                    deltaPp: $cell->deltaPp,
                    heatClass: $cell->heatClass,
                    rank: $cell->rank,
                    rankDelta: null !== $previousRank && null !== $cell->rank ? $previousRank - $cell->rank : null,
                    enteredTop: $enteredTop,
                    entityLabel: $cell->entityLabel,
                    linkUrl: $cell->linkUrl,
                    entityId: $cell->entityId,
                );
            }
            $rows[] = new TransportTimeProfileMatrixRow($row->key, $row->label, $row->kind, $cells, $row->overallPercent);
        }

        return new TransportTimeProfileMatrixSection($section->key, $section->titleKey, $rows);
    }

    /**
     * @param array<string, array<int|string, int>> $byBucket
     *
     * @return array<string, int>
     */
    private function dimensionCounts(array $byBucket, string $code): array
    {
        $counts = [];
        foreach (StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS as $bucket) {
            $counts[$bucket] = $byBucket[$bucket][$code] ?? 0;
        }

        return $counts;
    }

    /**
     * @param array<string, array<int|string, int>> $byBucket
     *
     * @return array<string, int>
     */
    private function trueCounts(array $byBucket): array
    {
        return $this->dimensionCounts($byBucket, '1');
    }

    /**
     * @param list<string>                                 $labels
     * @param list<array{name: string, data: list<float>}> $series
     *
     * @return array<string, mixed>
     */
    private function percentStackedSpec(array $labels, array $series, string $axisLabel): array
    {
        return [
            'chartType' => 'bar',
            'stacked' => true,
            'percentScale' => true,
            'labels' => $labels,
            'series' => $series,
            'xAxisLabel' => $axisLabel,
            'yAxisLabel' => '%',
        ];
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<array{name: string, data: list<float>}>
     */
    private function genderSeries(
        TransportTimeProfileSliceData $slice,
        array $volume,
        array $buckets,
        string $locale,
    ): array {
        $series = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $code) {
            $series[] = [
                'name' => $this->translator->trans($code->labelTranslationKey(), [], 'messages', $locale),
                'data' => $this->percentSeries($this->dimensionCounts($slice->genderByBucket, (string) $code->value), $volume, $buckets),
            ];
        }

        $unknown = $this->dimensionCounts($slice->genderByBucket, 'unknown');
        if (array_sum($unknown) > 0) {
            $series[] = [
                'name' => $this->translator->trans('stats.indication.gender.unknown', [], 'statistics', $locale),
                'data' => $this->percentSeries($unknown, $volume, $buckets),
            ];
        }

        return $series;
    }

    /**
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<array{name: string, data: list<float>}>
     */
    private function urgencySeries(
        TransportTimeProfileSliceData $slice,
        array $volume,
        array $buckets,
        string $locale,
    ): array {
        $series = [];
        foreach (AllocationUrgency::cases() as $urgency) {
            $series[] = [
                'name' => $this->translator->trans($urgency->label(), [], 'messages', $locale),
                'data' => $this->percentSeries(
                    $this->dimensionCounts($slice->urgencyByBucket, (string) $urgency->value),
                    $volume,
                    $buckets,
                ),
            ];
        }

        return $series;
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $volume
     * @param list<string>       $buckets
     *
     * @return list<float>
     */
    private function percentSeries(array $counts, array $volume, array $buckets): array
    {
        $data = [];
        foreach ($buckets as $bucket) {
            $data[] = TransportTimeProfileMath::percent($counts[$bucket] ?? 0, $volume[$bucket]);
        }

        return $data;
    }
}
