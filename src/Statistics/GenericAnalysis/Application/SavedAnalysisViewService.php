<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\User\Domain\Entity\User;

final readonly class SavedAnalysisViewService
{
    public function __construct(
        private SavedAnalysisViewRepository $repository,
    ) {
    }

    public function create(
        User $owner,
        string $title,
        AnalysisViewConfig $config,
        ?string $description = null,
        ?string $sourceSystemViewKey = null,
    ): SavedAnalysisView {
        $saved = new SavedAnalysisView(
            owner: $owner,
            title: $title,
            config: $config,
            description: $description,
            sourceSystemViewKey: $sourceSystemViewKey,
            visibility: AnalysisViewVisibility::Private,
        );
        $this->repository->save($saved);

        return $saved;
    }

    public function update(
        SavedAnalysisView $saved,
        string $title,
        ?string $description,
        AnalysisViewConfig $config,
    ): void {
        $saved->setTitle($title);
        $saved->setDescription($description);
        $saved->setConfig($config);
        $this->repository->save($saved);
    }

    public function delete(SavedAnalysisView $saved): void
    {
        $this->repository->remove($saved);
    }

    public static function configFromResolved(
        ResolvedGenericAnalysisConfig $config,
        ?string $layout = null,
        ?int $top = null,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            primaryDimensionKey: $config->primaryDimensionKey,
            secondaryDimensionKey: $config->seriesDimensionKey,
            metricKeys: $config->query->metricKeys,
            visualMetricKey: $config->query->visualMetricKey,
            chartType: $config->query->chartType?->value,
            includeNullBuckets: $config->includeNullBuckets,
            layout: $layout,
            top: $top,
            seriesMode: $config->query->seriesMode->value,
            displayMode: $config->query->displayMode->value,
            chartMetricKeys: $config->query->chartMetricKeys,
            configVersion: 3,
            dataSource: $config->query->dataSource,
            hospitalPopulationMode: $config->query->hospitalPopulationMode->value,
        );
    }
}
