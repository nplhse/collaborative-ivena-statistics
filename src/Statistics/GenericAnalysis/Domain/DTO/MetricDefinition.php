<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalMetricClass;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricComputationKind;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricFormat;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricSourceType;
use App\Statistics\GenericAnalysis\Domain\Enum\MetricType;

/**
 * Whitelisted metric definition. SQL expressions and source columns must never come from user input.
 */
final readonly class MetricDefinition
{
    /**
     * @param list<string> $requiredBaseMetricKeys
     */
    public function __construct(
        public string $key,
        public string $label,
        public MetricType $metricType,
        public MetricComputationKind $computationKind,
        public ?string $sqlSelectExpression = null,
        public ?string $description = null,
        public ?string $sourceColumn = null,
        public ?MetricSourceType $requiredSourceType = null,
        public array $requiredBaseMetricKeys = [],
        public bool $requiresSeriesDimension = false,
        public bool $supportsPrimaryDimension = true,
        public bool $supportsSeriesDimension = true,
        public bool $supportsRelativeMode = false,
        public MetricFormat $defaultFormat = MetricFormat::Integer,
        public int $defaultPrecision = 0,
        public bool $isDefault = false,
        public bool $isExperimental = false,
        public int $sortPriority = 100,
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
        public ?HospitalMetricClass $hospitalMetricClass = null,
    ) {
    }
}
