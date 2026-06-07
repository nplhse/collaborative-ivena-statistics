<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\DTO\HospitalSummaryData;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\SummaryDeckWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewDashboardMetricsResult;

final readonly class HospitalSummaryProvider
{
    /** @var array<string, string> AllocationGender::value => Tabler progress-bar class */
    private const array GENDER_BAR_CLASSES = [
        'M' => 'bg-primary',
        'F' => 'bg-pink',
        'X' => 'bg-purple',
    ];

    /** @var array<int, string> AllocationUrgency::value => Tabler progress-bar class */
    private const array URGENCY_BAR_CLASSES = [
        1 => 'bg-red',
        2 => 'bg-yellow',
        3 => 'bg-green',
    ];

    /** @var array<int, string> translation keys for U1–U3 style labels */
    private const array URGENCY_SHORT_LABEL_KEYS = [
        1 => 'stats.overview.hospital_summary.urgency_u1',
        2 => 'stats.overview.hospital_summary.urgency_u2',
        3 => 'stats.overview.hospital_summary.urgency_u3',
    ];

    public function __construct(
        private HospitalSummaryQuery $hospitalSummaryQuery,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
    ) {
    }

    /**
     * @return list<StatisticWidget>
     */
    public function build(StatisticsContext $context, OverviewDashboardMetricsResult $metrics): array
    {
        $data = $this->hospitalSummaryQuery->summarize($context, $metrics);

        return [
            $this->summaryDeckWidget($data),
        ];
    }

    private function summaryDeckWidget(HospitalSummaryData $data): StatisticWidget
    {
        $total = $data->totalAllocationsInPeriod;
        $user = $data->userHospitalsAllocationsInPeriod;
        $percent = $total > 0 ? round(100 * $user / $total, 1) : 0.0;

        $genderSegments = [];
        foreach (AllocationGender::cases() as $case) {
            $count = $data->genderCounts[$case->value] ?? 0;
            $sharePercent = $user > 0 ? round(100 * $count / $user, 1) : 0.0;
            $genderSegments[] = [
                'barClass' => self::GENDER_BAR_CLASSES[$case->value] ?? 'bg-secondary',
                'labelTranslationKey' => $case->label(),
                'count' => $count,
                'percent' => $sharePercent,
            ];
        }

        $urgencySegments = [];
        foreach (AllocationUrgency::cases() as $case) {
            $count = $data->urgencyCounts[$case->value] ?? 0;
            $sharePercent = $user > 0 ? round(100 * $count / $user, 1) : 0.0;
            $urgencySegments[] = [
                'barClass' => self::URGENCY_BAR_CLASSES[$case->value] ?? 'bg-secondary',
                'labelTranslationKey' => self::URGENCY_SHORT_LABEL_KEYS[$case->value] ?? $case->label(),
                'count' => $count,
                'percent' => $sharePercent,
            ];
        }

        return new StatisticWidget(
            StatisticWidgetType::SummaryDeck,
            'hospital_summary_deck',
            $this->widgetPayloadNormalizer->normalize(new SummaryDeckWidgetPayload(
                [
                    'subheaderTranslationKey' => 'stats.overview.hospital_summary.kpi_subheader',
                    'value' => $user,
                    'mutedTranslationKey' => 'stats.overview.hospital_summary.kpi_subline',
                    'totalCount' => $total,
                    'percent' => $percent,
                ],
                [
                    'titleTranslationKey' => 'stats.overview.hospital_summary.gender_card_title',
                    'segments' => $genderSegments,
                ],
                [
                    'titleTranslationKey' => 'stats.overview.hospital_summary.urgency_card_title',
                    'segments' => $urgencySegments,
                ],
                ['showUnscopedHint' => false],
            )),
        );
    }
}
