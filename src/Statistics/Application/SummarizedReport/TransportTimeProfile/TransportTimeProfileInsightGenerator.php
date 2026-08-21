<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport\TransportTimeProfile;

use App\Statistics\Application\Insights\HospitalInsightTrend;
use App\Statistics\Application\Mapping\StatisticsTransportTimeBucketSql;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileInsight;
use App\Statistics\Application\SummarizedReport\TransportTimeProfile\Dto\TransportTimeProfileMatrixSection;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransportTimeProfileInsightGenerator
{
    private const int MAX_INSIGHTS = 4;

    public function __construct(
        private TranslatorInterface $translator,
        private TransportTimeProfileAssembler $assembler,
    ) {
    }

    /**
     * @param array<string, int>                      $volume
     * @param list<TransportTimeProfileMatrixSection> $sections
     * @param array<string, string>                   $bucketLabels
     *
     * @return list<TransportTimeProfileInsight>
     */
    public function generate(
        array $volume,
        array $sections,
        array $bucketLabels,
        int $populationN,
        string $locale,
    ): array {
        if ($populationN < TransportTimeProfileMath::INSIGHT_MIN_POPULATION_N) {
            return [];
        }

        $short = $this->firstEligibleBucket($volume);
        $long = $this->lastEligibleBucket($volume);
        if (null === $short || null === $long || $short === $long) {
            return [];
        }

        $candidates = [];

        $this->addRateSpan(
            $candidates,
            'urgency_emergency',
            $this->assembler->rateSeriesByRow($sections, 'urgency', '1'),
            $volume,
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRateSpan(
            $candidates,
            'physician',
            $this->assembler->rateSeriesByRow($sections, 'physician', 'with_physician'),
            $volume,
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRateSpan(
            $candidates,
            'resus',
            $this->assembler->rateSeriesByRow($sections, 'resources', 'requires_resus'),
            $volume,
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRateSpan(
            $candidates,
            'cathlab',
            $this->assembler->rateSeriesByRow($sections, 'resources', 'requires_cathlab'),
            $volume,
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRateSpan(
            $candidates,
            'air',
            $this->assembler->rateSeriesByRow($sections, 'transport_mode', '2'),
            $volume,
            $short,
            $long,
            $bucketLabels,
            $locale,
        );

        $this->addRankChange(
            $candidates,
            'departments',
            $this->assembler->rankedByBucket($sections, 'departments'),
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRankChange(
            $candidates,
            'specialities',
            $this->assembler->rankedByBucket($sections, 'specialities'),
            $short,
            $long,
            $bucketLabels,
            $locale,
        );
        $this->addRankChange(
            $candidates,
            'indications',
            $this->assembler->rankedByBucket($sections, 'indications'),
            $short,
            $long,
            $bucketLabels,
            $locale,
        );

        $this->addOverallDeviation(
            $candidates,
            $this->assembler->rateSeriesByRow($sections, 'transport_mode', '2'),
            $volume,
            $long,
            $locale,
        );

        return \array_slice($candidates, 0, self::MAX_INSIGHTS);
    }

    /**
     * @param array<string, int> $volume
     */
    public function firstEligibleBucket(array $volume): ?string
    {
        foreach (StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS as $bucket) {
            if (($volume[$bucket] ?? 0) >= TransportTimeProfileMath::INSIGHT_MIN_BUCKET_N) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $volume
     */
    public function lastEligibleBucket(array $volume): ?string
    {
        foreach (array_reverse(StatisticsTransportTimeBucketSql::DISPLAY_BUCKET_KEYS) as $bucket) {
            if (($volume[$bucket] ?? 0) >= TransportTimeProfileMath::INSIGHT_MIN_BUCKET_N) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * @param list<TransportTimeProfileInsight> $candidates
     * @param array<string, float>              $percents
     * @param array<string, int>                $volume
     * @param array<string, string>             $bucketLabels
     */
    private function addRateSpan(
        array &$candidates,
        string $id,
        array $percents,
        array $volume,
        string $short,
        string $long,
        array $bucketLabels,
        string $locale,
    ): void {
        if (($volume[$short] ?? 0) < TransportTimeProfileMath::INSIGHT_MIN_BUCKET_N
            || ($volume[$long] ?? 0) < TransportTimeProfileMath::INSIGHT_MIN_BUCKET_N) {
            return;
        }

        $from = $percents[$short] ?? 0.0;
        $to = $percents[$long] ?? 0.0;
        $delta = round($to - $from, 1);
        if (abs($delta) < TransportTimeProfileMath::INSIGHT_MIN_PP) {
            return;
        }

        $direction = $delta > 0 ? 'increase' : 'decrease';
        $candidates[] = new TransportTimeProfileInsight(
            $id,
            $this->translator->trans(
                'stats.reports.transport_time_profile.insight.'.$id.'.title',
                [],
                'statistics',
                $locale,
            ),
            $this->translator->trans(
                'stats.reports.transport_time_profile.insight.rate_'.$direction,
                [
                    'metric' => $this->translator->trans(
                        'stats.reports.transport_time_profile.insight.metric.'.$id,
                        [],
                        'statistics',
                        $locale,
                    ),
                    'from_percent' => $from,
                    'to_percent' => $to,
                    'from_bucket' => $bucketLabels[$short] ?? $short,
                    'to_bucket' => $bucketLabels[$long] ?? $long,
                ],
                'statistics',
                $locale,
            ),
            $delta > 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
        );
    }

    /**
     * @param list<TransportTimeProfileInsight>                                                $candidates
     * @param array<string, list<array{id: string, label: string, percent: float, rank: int}>> $byBucket
     * @param array<string, string>                                                            $bucketLabels
     */
    private function addRankChange(
        array &$candidates,
        string $id,
        array $byBucket,
        string $short,
        string $long,
        array $bucketLabels,
        string $locale,
    ): void {
        $shortItems = $byBucket[$short] ?? [];
        $longItems = $byBucket[$long] ?? [];
        if ([] === $shortItems || [] === $longItems) {
            return;
        }

        $shortById = [];
        foreach ($shortItems as $item) {
            $shortById[$item['id']] = $item;
        }

        foreach ($longItems as $longItem) {
            $shortItem = $shortById[$longItem['id']] ?? null;
            if (null === $shortItem) {
                continue;
            }
            $rankChange = $shortItem['rank'] - $longItem['rank'];
            $percentDelta = abs($longItem['percent'] - $shortItem['percent']);
            if (abs($rankChange) < TransportTimeProfileMath::INSIGHT_MIN_RANK_CHANGE
                || $percentDelta < TransportTimeProfileMath::INSIGHT_MIN_PP) {
                continue;
            }

            $candidates[] = new TransportTimeProfileInsight(
                $id.'_'.$longItem['id'],
                $this->translator->trans(
                    'stats.reports.transport_time_profile.insight.rank.title',
                    ['label' => $longItem['label']],
                    'statistics',
                    $locale,
                ),
                $this->translator->trans(
                    'stats.reports.transport_time_profile.insight.rank.body',
                    [
                        'label' => $longItem['label'],
                        'from_rank' => $shortItem['rank'],
                        'to_rank' => $longItem['rank'],
                        'from_bucket' => $bucketLabels[$short] ?? $short,
                        'to_bucket' => $bucketLabels[$long] ?? $long,
                    ],
                    'statistics',
                    $locale,
                ),
                $rankChange > 0 ? HospitalInsightTrend::Up : HospitalInsightTrend::Down,
            );

            return;
        }
    }

    /**
     * @param list<TransportTimeProfileInsight> $candidates
     * @param array<string, float>              $percents
     * @param array<string, int>                $volume
     */
    private function addOverallDeviation(
        array &$candidates,
        array $percents,
        array $volume,
        string $long,
        string $locale,
    ): void {
        if (($volume[$long] ?? 0) < TransportTimeProfileMath::INSIGHT_MIN_DEVIATION_N) {
            return;
        }

        $knownTotal = array_sum($volume);
        if ($knownTotal <= 0) {
            return;
        }

        $weighted = 0.0;
        foreach ($percents as $bucket => $percent) {
            $weighted += $percent * (float) ($volume[$bucket] ?? 0);
        }
        $overall = round($weighted / (float) $knownTotal, 1);
        $bucketPercent = $percents[$long] ?? 0.0;
        $delta = round($bucketPercent - $overall, 1);
        if ($delta < TransportTimeProfileMath::INSIGHT_MIN_OVERALL_PP) {
            return;
        }

        $candidates[] = new TransportTimeProfileInsight(
            'air_overrepresented',
            $this->translator->trans(
                'stats.reports.transport_time_profile.insight.air_overrepresented.title',
                [],
                'statistics',
                $locale,
            ),
            $this->translator->trans(
                'stats.reports.transport_time_profile.insight.air_overrepresented.body',
                [
                    'bucket_percent' => $bucketPercent,
                    'overall_percent' => $overall,
                ],
                'statistics',
                $locale,
            ),
            HospitalInsightTrend::Up,
        );
    }
}
