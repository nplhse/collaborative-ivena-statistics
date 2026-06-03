<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;

final readonly class GenericAnalysisService
{
    public function __construct(
        private GenericAllocationAnalysisQuery $analysisQuery,
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
        $raw = $this->analysisQuery->execute($query);
        $enriched = $this->relativeDistributionCalculator->enrich($raw);

        return $this->resultNormalizer->normalize($raw, $title, $enriched);
    }
}
