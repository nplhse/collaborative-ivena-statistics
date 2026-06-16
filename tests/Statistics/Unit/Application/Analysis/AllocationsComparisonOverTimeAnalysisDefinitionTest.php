<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Analysis;

use App\Statistics\Application\Analysis\AllocationsComparisonOverTimeAnalysisDefinition;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class AllocationsComparisonOverTimeAnalysisDefinitionTest extends DatabaseKernelTestCase
{
    public function testBuildUsesHospitalsLabelForAdminMyHospitalsScope(): void
    {
        self::bootKernel();
        $definition = self::getContainer()->get(AllocationsComparisonOverTimeAnalysisDefinition::class);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $primary = new StatisticsFilter(StatisticsFilterScope::MyHospitals, null, null, StatisticsFilterPeriod::All);
        $comparison = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All);
        $context = new StatisticsContext($admin, $primary, comparisonFilter: $comparison);

        self::assertTrue($definition->supports($context));

        $widget = $definition->build(
            $context,
            'table',
            'line',
            StatisticsAnalysisDimension::Total,
            StatisticsChartMeasure::Absolute,
        );

        self::assertSame(StatisticWidgetType::Table, $widget->type);
        $headers = $widget->payload['headerTranslationKeys'] ?? null;
        self::assertIsArray($headers);
        self::assertStringContainsString('Hospitals', (string) $headers[1]);
    }
}
