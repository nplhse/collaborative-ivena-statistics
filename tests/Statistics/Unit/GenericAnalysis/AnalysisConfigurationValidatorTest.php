<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisConfigurationValidator;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Exception\InvalidAnalysisConfigurationException;
use PHPUnit\Framework\TestCase;

final class AnalysisConfigurationValidatorTest extends TestCase
{
    private AnalysisConfigurationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = GenericAnalysisTestFixtures::configurationValidator();
    }

    public function testRejectsSeriesDimensionWithByMetricMode(): void
    {
        $this->expectException(InvalidAnalysisConfigurationException::class);

        $this->validator->validateQuery(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'urgency',
            metricKeys: ['count', 'resus_rate', 'cathlab_rate'],
            seriesMode: AnalysisSeriesMode::ByMetric,
        ));
    }

    public function testRejectsPieWithSeriesDimension(): void
    {
        $this->expectException(InvalidAnalysisConfigurationException::class);

        $this->validator->validateQuery(new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
            seriesDimensionKey: 'urgency',
            chartType: GenericAnalysisChartType::Pie,
        ));
    }
}
