<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;

/**
 * Whitelisted dimension metadata (column/expression comes only from the registry).
 */
final readonly class AnalysisDimension
{
    /**
     * @param list<int|string>          $fixedBuckets
     * @param array<int|string, string> $valueLabels
     * @param array<int|string, string> $valueLabelTranslationKeys
     * @param list<string>              $nullBucketKeys            Bucket keys representing missing source data (e.g. age_group "unknown")
     */
    public function __construct(
        public string $key,
        public string $column,
        public string $label,
        public AnalysisDimensionType $type,
        public string $recommendedChartType = 'bar',
        public ?string $sqlExpression = null,
        /** @var list<int|string> */
        public array $fixedBuckets = [],
        /** @var array<int|string, string> */
        public array $valueLabels = [],
        /** @var array<int|string, string> */
        public array $valueLabelTranslationKeys = [],
        public bool $sortAscending = true,
        public array $nullBucketKeys = [],
        public ?string $requiresNonNullSourceColumn = null,
        public bool $preserveAllBuckets = false,
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
    ) {
    }

    public function selectExpression(): string
    {
        return $this->sqlExpression ?? $this->column;
    }
}
