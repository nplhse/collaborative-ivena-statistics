<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\UI\Http\Controller\AnalysisFilterFactory;
use App\Statistics\UI\Http\Controller\AnalysisKeyAliasResolver;
use App\Statistics\UI\Http\Controller\AnalysisRequestModelFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalysisRequestModelFactoryTest extends TestCase
{
    public function testNormalizesAliasesAndInvalidValues(): void
    {
        $factory = new AnalysisRequestModelFactory(
            new AnalysisFilterFactory(new AnalysisKeyAliasResolver())
        );
        $request = new Request(query: [
            'analysis' => 'pivot',
            'view' => 'invalid',
            'chart' => 'invalid',
            'dimension' => 'resources',
            'chart_measure' => 'share',
            'rows' => 'urgency',
            'cols' => 'gender',
            'measure' => 'row_percent',
        ]);

        $model = $factory->fromRequest($request);

        self::assertSame('allocation_pivot', $model->analysisKey);
        self::assertSame('chart', $model->view);
        self::assertSame('bar', $model->chartType);
        self::assertSame(StatisticsAnalysisDimension::Resources, $model->dimension);
        self::assertSame(StatisticsChartMeasure::Share, $model->chartMeasure);
        self::assertSame('urgency', $model->rows);
        self::assertSame('gender', $model->cols);
        self::assertSame('row_percent', $model->measure);
    }

    public function testForcesAbsoluteMeasureForFeaturesDimension(): void
    {
        $factory = new AnalysisRequestModelFactory(
            new AnalysisFilterFactory(new AnalysisKeyAliasResolver())
        );
        $request = new Request(query: [
            'analysis' => 'allocations_over_time',
            'dimension' => 'features',
            'chart_measure' => 'share',
        ]);

        $model = $factory->fromRequest($request);

        self::assertSame('allocations_by_month', $model->analysisKey);
        self::assertSame(StatisticsAnalysisDimension::Features, $model->dimension);
        self::assertSame(StatisticsChartMeasure::Absolute, $model->chartMeasure);
    }
}
