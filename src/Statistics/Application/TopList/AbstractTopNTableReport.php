<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\TopEntityQuery;

abstract readonly class AbstractTopNTableReport implements TopListDefinitionInterface
{
    public function __construct(
        private TopEntityQuery $topEntityQuery,
        private TopListLimitPolicy $reportLimitPolicy,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
    ) {
    }

    abstract protected function projectionJoinProperty(): string;

    abstract protected function entityFqcn(): string;

    abstract protected function tableLabelColumnTranslationKey(): string;

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function build(StatisticsContext $context, int $limit): StatisticWidget
    {
        $data = $this->topEntityQuery->fetch(
            $context,
            $limit,
            $this->projectionJoinProperty(),
            $this->entityFqcn(),
        );
        $total = $data['totalAllocations'];
        $rows = [];
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
            ++$rank;
        }

        $payload = new TableWidgetPayload(
            [
                'stats.top_lists.table.rank',
                $this->tableLabelColumnTranslationKey(),
                'stats.top_lists.table.count',
                'stats.top_lists.table.share',
            ],
            $rows,
            ['numericColumnStartIndex' => 3],
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
