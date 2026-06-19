<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;

final readonly class GenericAnalysisService
{
    public function __construct(
        private AnalysisQueryExecutorRegistry $executorRegistry,
        private AnalysisQueryModifierRegistry $modifierRegistry,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
        private RelativeDistributionCalculator $relativeDistributionCalculator,
        private ResultNormalizer $resultNormalizer,
    ) {
    }

    public function runPreset(AnalysisPreset $preset, AnalysisQuery $query): NormalizedAnalysisResult
    {
        return $this->run($preset->title, $query);
    }

    public function run(string $title, AnalysisQuery $query): NormalizedAnalysisResult
    {
        $this->modifierRegistry->validate($query);
        $sqlQuery = $this->modifierRegistry->prepareForExecution($query);
        $this->metricCompatibilityChecker->resolveAndValidate($sqlQuery);
        $raw = $this->executorRegistry->get($sqlQuery->dataSource)->execute($sqlQuery);
        $enriched = $this->relativeDistributionCalculator->enrich($raw, $raw->metricKeys);

        return $this->resultNormalizer->normalize($raw, $title, $enriched, $sqlQuery);
    }
}
