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
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TopDiagnosesReport implements TopListDefinitionInterface
{
    public function __construct(
        private TopDiagnosesQuery $topDiagnosesQuery,
        private TopListLimitPolicy $reportLimitPolicy,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private TranslatorInterface $translator,
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
    public function icon(): string
    {
        return 'tabler:id';
    }

    #[\Override]
    public function tableLabelColumnTranslationKey(): string
    {
        return 'stats.top_lists.table.diagnosis';
    }

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function fetchRanking(StatisticsContext $context, int $limit): TopListRanking
    {
        $data = $this->topDiagnosesQuery->fetch($context, $limit);

        return TopListRanking::fromAggregates(
            $data['rows'],
            $data['totalAllocations'],
            $limit,
            $this->translator->trans(TopListRanking::UNKNOWN_TRANSLATION_KEY, [], 'statistics'),
        );
    }

    #[\Override]
    public function toTableWidget(TopListRanking $ranking): StatisticWidget
    {
        $rows = [];
        $diagnosisRowTargets = [];

        foreach ($ranking->rows as $row) {
            $rows[] = [
                (string) $row->rank,
                $row->label,
                (string) $row->count,
                sprintf('%.1f%%', $row->share),
            ];
            $diagnosisRowTargets[] = null !== $row->entityId
                ? new StatisticWidgetNavigationTarget(
                    'stats.top_lists.nav.indication_profile',
                    'app_stats_indication_dashboard',
                    ['indicationId' => $row->entityId],
                    ['report', 'limit', 'view', 'chart'],
                )
                : null;
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
    public function build(StatisticsContext $context, int $limit): StatisticWidget
    {
        return $this->toTableWidget($this->fetchRanking($context, $limit));
    }

    #[\Override]
    public function allowedLimits(): array
    {
        return $this->reportLimitPolicy->allowed();
    }
}
