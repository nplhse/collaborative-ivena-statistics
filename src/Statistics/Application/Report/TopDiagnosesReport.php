<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\TopDiagnosesQuery;

final readonly class TopDiagnosesReport implements ReportDefinitionInterface
{
    public function __construct(
        private TopDiagnosesQuery $topDiagnosesQuery,
        private ReportLimitPolicy $reportLimitPolicy,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'top_diagnoses';
    }

    #[\Override]
    public function labelTranslationKey(): string
    {
        return 'stats.reports.top_diagnoses.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.reports.top_diagnoses.description';
    }

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function build(StatisticsContext $context, int $limit): StatisticWidget
    {
        $data = $this->topDiagnosesQuery->fetch($context, $limit);
        $total = $data['totalAllocations'];
        $rows = [];
        $diagnosisRowTargets = [];
        $rank = 1;

        foreach ($data['rows'] as $row) {
            $count = $row['count'];
            $pct = $total > 0 ? round(100 * $count / $total, 1) : 0.0;
            $rows[] = [
                (string) $rank,
                $row['label'],
                (string) $count,
                sprintf('%.1f%%', $pct),
            ];
            $diagnosisRowTargets[] = isset($row['indicationId'])
                ? new StatisticWidgetNavigationTarget(
                    'stats.reports.nav.indication_profile',
                    'app_stats_indication_dashboard',
                    ['indicationId' => $row['indicationId']],
                    ['report', 'limit', 'view', 'chart'],
                )
                : null;
            ++$rank;
        }

        $payload = new TableWidgetPayload(
            [
                'stats.reports.table.rank',
                'stats.reports.table.diagnosis',
                'stats.reports.table.count',
                'stats.reports.table.share',
            ],
            $rows,
            [
                'numericColumnStartIndex' => 3,
                'diagnosisRowTargets' => $diagnosisRowTargets,
            ],
        );

        return new StatisticWidget(
            StatisticWidgetType::Table,
            $this->key().'_table',
            $this->widgetPayloadNormalizer->normalize($payload),
            null,
            [
                new StatisticWidgetNavigationTarget(
                    'stats.nav.report_to_analysis_allocations_by_month',
                    'app_stats_analysis',
                    ['analysis' => 'allocations_by_month'],
                    ['report', 'limit', 'view', 'chart'],
                ),
            ],
        );
    }

    #[\Override]
    public function allowedLimits(): array
    {
        return $this->reportLimitPolicy->allowed();
    }
}
