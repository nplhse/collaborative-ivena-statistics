<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\DTO\WidgetPayload\TableWidgetPayload;
use App\Statistics\Application\DTO\WidgetPayload\WidgetPayloadNormalizer;
use App\Statistics\Application\TopEntityQuery;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract readonly class AbstractTopNTableReport implements TopListDefinitionInterface
{
    public function __construct(
        private TopEntityQuery $topEntityQuery,
        private TopListLimitPolicy $reportLimitPolicy,
        private WidgetPayloadNormalizer $widgetPayloadNormalizer,
        private TranslatorInterface $translator,
        private TopListCatalogCrossReference $catalogCrossReference,
    ) {
    }

    abstract protected function projectionJoinProperty(): string;

    abstract protected function entityFqcn(): string;

    #[\Override]
    abstract public function tableLabelColumnTranslationKey(): string;

    #[\Override]
    abstract public function catalogDimension(): CatalogDimensionKey;

    protected function requireJoinedEntity(): bool
    {
        return false;
    }

    #[\Override]
    public function supports(StatisticsFilter $filter): bool
    {
        return true;
    }

    #[\Override]
    public function fetchRanking(StatisticsContext $context, int $limit): TopListRanking
    {
        $data = $this->topEntityQuery->fetch(
            $context,
            $limit,
            $this->projectionJoinProperty(),
            $this->entityFqcn(),
            requireJoinedEntity: $this->requireJoinedEntity(),
        );

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
        $labelRowTargets = [];
        foreach ($ranking->rows as $row) {
            $rows[] = [
                (string) $row->rank,
                $row->label,
                (string) $row->count,
                sprintf('%.1f%%', $row->share),
            ];
            $labelRowTargets[] = $this->catalogCrossReference->labelRowTarget(
                $this->key(),
                $row->publicId,
            );
        }

        $payload = new TableWidgetPayload(
            [
                'stats.top_lists.table.rank',
                $this->tableLabelColumnTranslationKey(),
                'stats.top_lists.table.count',
                'stats.top_lists.table.share',
            ],
            $rows,
            [
                'numericColumnStartIndex' => 3,
                'labelRowTargets' => $labelRowTargets,
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
