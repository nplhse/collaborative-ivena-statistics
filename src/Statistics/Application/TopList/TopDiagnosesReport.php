<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\TopDiagnosesQuery;

final readonly class TopDiagnosesReport implements TopListDefinitionInterface
{
    public function __construct(
        private TopDiagnosesQuery $topDiagnosesQuery,
        private TopListLimitPolicy $reportLimitPolicy,
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
        return 'stats.top_lists.top_diagnoses.label';
    }

    #[\Override]
    public function descriptionTranslationKey(): string
    {
        return 'stats.top_lists.top_diagnoses.description';
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
                    'stats.top_lists.nav.indication_profile',
                    'app_stats_indication_dashboard',
                    ['indicationId' => $row['indicationId']],
                    ['report', 'limit', 'view', 'chart'],
                )
                : null;
            ++$rank;
        }

        $payload = new TableWidgetPayload(
            [
                'stats.top_lists.table.rank',
                'stats.top_lists.table.diagnosis',
                'stats.top_lists.table.count',
                'stats.top_lists.table.share',
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
        );
    }

    #[\Override]
    public function allowedLimits(): array
    {
        return $this->reportLimitPolicy->allowed();
    }
}
